<?php

namespace Tests\Feature;

use App\Models\PublisherPayment;
use App\Models\PublisherPlan;
use App\Models\PublisherSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublisherPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const CREATE = 'https://api.sonicpesa.com/api/v1/payment/create_order';

    private const STATUS = 'https://api.sonicpesa.com/api/v1/payment/order_status';

    private function configure(): void
    {
        config(['services.sonicpesa.key' => 'test-key', 'services.sonicpesa.secret' => 'test-secret']);
        Http::preventStrayRequests();
    }

    private function confirmed(array $replace = []): array
    {
        return ['status' => 'success', 'data' => array_replace(['order_id' => 'sp_test', 'payment_status' => 'SUCCESS', 'amount' => '10000.00', 'currency' => 'TZS', 'phone' => '255712345678', 'transid' => 'TX_TEST'], $replace)];
    }

    private function checkoutData(): array
    {
        return ['phone' => '255712345678', 'quoted_price' => '10000.00', 'quoted_duration' => 30, 'accept_policies' => true];
    }

    public function test_checkout_creates_one_mobile_money_request_and_duplicate_submissions_reuse_it(): void
    {
        $this->configure();
        Http::fake([self::CREATE => Http::response(['status' => 'success', 'data' => ['order_id' => 'sp_test', 'status' => 'PENDING']])]);
        $publisher = User::factory()->verified()->create();
        $plan = PublisherPlan::factory()->create();
        $this->actingAs($publisher, 'web');

        $this->post('/subscriptions/plans/'.$plan->id.'/checkout', $this->checkoutData())->assertRedirect();
        $this->post('/subscriptions/plans/'.$plan->id.'/checkout', $this->checkoutData())->assertRedirect();
        $payment = PublisherPayment::firstOrFail();
        $this->assertDatabaseCount('publisher_payments', 1);
        $this->assertDatabaseHas('publisher_payments', ['id' => $payment->id, 'user_id' => $publisher->id, 'amount' => 10000, 'status' => 'pending', 'provider_order_id' => 'sp_test']);
        $this->assertDatabaseCount('publisher_subscriptions', 0);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === self::CREATE && $request->hasHeader('X-API-KEY', 'test-key') && $request->hasHeader('X-API-SECRET', 'test-secret') && $request['buyer_email'] === $publisher->email && $request['currency'] === 'TZS');
        $this->get('/subscriptions/payments/'.$payment->reference)->assertOk()->assertSee('Approve the SonicPesa payment prompt');
    }

    public function test_checkout_refuses_unverified_publishers_inactive_plans_and_changed_quotes(): void
    {
        $this->configure();
        Http::fake();
        $plan = PublisherPlan::factory()->create();
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $this->post('/subscriptions/plans/'.$plan->id.'/checkout', $this->checkoutData())->assertForbidden();
        $user->update(['verified' => true]);
        $this->post('/subscriptions/plans/'.$plan->id.'/checkout', array_replace($this->checkoutData(), ['quoted_price' => 1]))->assertSessionHasErrors('plan');
        $this->post('/subscriptions/plans/'.$plan->id.'/checkout', array_replace($this->checkoutData(), ['phone' => 'invalid']))->assertSessionHasErrors('phone');
        $plan->update(['is_active' => false]);
        $this->post('/subscriptions/plans/'.$plan->id.'/checkout', $this->checkoutData())->assertForbidden();
        $this->assertDatabaseCount('publisher_payments', 0);
        Http::assertNothingSent();
    }

    public function test_signed_callback_uses_server_verification_and_duplicate_callbacks_do_not_extend_twice(): void
    {
        $this->travelTo('2026-11-11 12:00:00');
        $this->configure();
        $payment = PublisherPayment::factory()->create(['provider_order_id' => 'sp_test']);
        Http::fake([self::STATUS => Http::response($this->confirmed())]);
        $body = json_encode(['event' => 'payment.success', 'order_id' => 'sp_test', 'amount' => 1, 'currency' => 'USD']);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SONICPESA_SIGNATURE' => hash_hmac('sha256', $body, 'test-secret')];

        $this->call('POST', '/api/billing/sonicpesa/webhook', [], [], [], $headers, $body)->assertNoContent();
        $this->call('POST', '/api/billing/sonicpesa/webhook', [], [], [], $headers, $body)->assertNoContent();
        $this->assertDatabaseHas('publisher_payments', ['id' => $payment->id, 'status' => 'paid', 'provider_transaction_id' => 'TX_TEST']);
        $this->assertDatabaseCount('publisher_subscriptions', 1);
        $this->assertSame('2026-12-11', PublisherSubscription::firstOrFail()->ends_at->format('Y-m-d'));
        Http::assertSentCount(1);
    }

    public function test_forged_callback_cannot_activate_a_subscription(): void
    {
        $this->configure();
        Http::fake();
        PublisherPayment::factory()->create(['provider_order_id' => 'sp_test']);

        $this->postJson('/api/billing/sonicpesa/webhook', ['order_id' => 'sp_test', 'status' => 'SUCCESS'], ['X-SonicPesa-Signature' => 'forged'])->assertForbidden();
        $this->assertDatabaseCount('publisher_subscriptions', 0);
        Http::assertNothingSent();
    }

    public static function mismatches(): array
    {
        return ['amount' => ['amount', 5000], 'currency' => ['currency', 'USD'], 'order' => ['order_id', 'sp_other'], 'transaction' => ['transid', null], 'payer' => ['phone', '255799999999'], 'malformed_transaction' => ['transid', []]];
    }

    #[DataProvider('mismatches')]
    public function test_payment_mismatches_do_not_grant_publishing(string $field, mixed $value): void
    {
        $this->configure();
        $payment = PublisherPayment::factory()->create(['provider_order_id' => 'sp_test']);
        $this->actingAs($payment->user, 'web');
        Http::fake([self::STATUS => Http::response($this->confirmed([$field => $value]))]);

        $this->postJson('/subscriptions/payments/'.$payment->reference.'/check')->assertUnprocessable()->assertJsonValidationErrors('payment');
        $this->assertDatabaseCount('publisher_subscriptions', 0);
        $this->assertDatabaseHas('publisher_payments', ['id' => $payment->id, 'status' => 'pending']);
    }

    public function test_other_accounts_cannot_read_or_check_someone_elses_payment(): void
    {
        $this->configure();
        Http::fake();
        $payment = PublisherPayment::factory()->create();
        $this->actingAs(User::factory()->create(), 'web');

        $this->get('/subscriptions/payments/'.$payment->reference)->assertNotFound();
        $this->post('/subscriptions/payments/'.$payment->reference.'/check')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_renewal_uses_the_paid_snapshot_and_preserves_remaining_days(): void
    {
        $this->travelTo('2026-11-11 12:00:00');
        $this->configure();
        $payment = PublisherPayment::factory()->create(['provider_order_id' => 'sp_test']);
        PublisherSubscription::factory()->create(['user_id' => $payment->user_id, 'publisher_plan_id' => $payment->publisher_plan_id, 'ends_at' => now()->addDays(10)]);
        $payment->plan->update(['price' => 20000, 'duration_days' => 90]);
        Http::fake([self::STATUS => Http::response($this->confirmed())]);
        $this->actingAs($payment->user, 'web');

        $this->post('/subscriptions/payments/'.$payment->reference.'/check')->assertRedirect();
        $this->assertSame('2026-12-21', PublisherSubscription::firstOrFail()->ends_at->format('Y-m-d'));
        $this->assertDatabaseHas('publisher_payments', ['id' => $payment->id, 'amount' => 10000, 'duration_days' => 30, 'status' => 'paid']);
    }

    public function test_uncertain_initiation_cannot_send_another_charge_request(): void
    {
        $this->configure();
        Http::fake([self::CREATE => Http::response(['status' => 'error'], 500)]);
        $this->actingAs(User::factory()->verified()->create(), 'web');
        $plan = PublisherPlan::factory()->create();

        $this->post('/subscriptions/plans/'.$plan->id.'/checkout', $this->checkoutData())->assertRedirect();
        $this->post('/subscriptions/plans/'.$plan->id.'/checkout', $this->checkoutData())->assertRedirect();
        $this->assertDatabaseCount('publisher_payments', 1);
        $this->assertDatabaseHas('publisher_payments', ['status' => 'unknown']);
        Http::assertSentCount(1);
    }

    public function test_admin_reconciliation_verifies_an_unknown_order_instead_of_marking_it_paid_manually(): void
    {
        $this->freezeTime();
        $this->configure();
        $payment = PublisherPayment::factory()->create(['provider_order_id' => null, 'status' => 'unknown']);
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin, 'web');
        Http::fake([self::STATUS => Http::response($this->confirmed())]);

        $this->post('/admin/payments/'.$payment->reference.'/verify', ['order_id' => 'sp_test'])->assertRedirect();
        $this->assertDatabaseHas('publisher_payments', ['id' => $payment->id, 'status' => 'paid']);
        $this->assertDatabaseHas('publisher_subscriptions', ['user_id' => $payment->user_id, 'status' => 'active']);
        $this->post('/admin/payments/'.$payment->reference.'/verify', ['order_id' => 'sp_test'])->assertConflict();
        Http::assertSentCount(1);
    }

    public function test_a_provider_transaction_cannot_pay_two_different_invoices(): void
    {
        $this->configure();
        $paid = PublisherPayment::factory()->create(['status' => 'paid', 'provider_transaction_id' => 'TX_TEST']);
        PublisherSubscription::factory()->create(['user_id' => $paid->user_id, 'publisher_plan_id' => $paid->publisher_plan_id]);
        $pending = PublisherPayment::factory()->create(['provider_order_id' => 'sp_test']);
        $this->actingAs($pending->user, 'web');
        Http::fake([self::STATUS => Http::response($this->confirmed())]);

        $this->postJson('/subscriptions/payments/'.$pending->reference.'/check')->assertUnprocessable();
        $this->assertDatabaseCount('publisher_subscriptions', 1);
        $this->assertDatabaseHas('publisher_payments', ['id' => $pending->id, 'status' => 'pending']);
    }

    public function test_a_delayed_failed_status_cannot_downgrade_a_completed_payment(): void
    {
        $this->configure();
        $payment = PublisherPayment::factory()->create(['provider_order_id' => 'sp_test']);
        $this->actingAs($payment->user, 'web');
        Http::fake([self::STATUS => function () use ($payment) {
            $payment->update(['status' => 'paid']);

            return Http::response($this->confirmed(['payment_status' => 'REJECTED']));
        }]);

        $this->postJson('/subscriptions/payments/'.$payment->reference.'/check')->assertOk()->assertJsonPath('status', 'paid');
        $this->assertDatabaseHas('publisher_payments', ['id' => $payment->id, 'status' => 'paid']);
    }

    public function test_publishing_requires_verification_and_a_current_paid_subscription(): void
    {
        $this->freezeTime();
        $publisher = User::factory()->verified()->create();
        Sanctum::actingAs($publisher);
        $event = ['title' => 'A new event', 'category' => 'music', 'location' => 'Dar', 'starts_at' => now()->addDay()->toIso8601String()];
        $this->getJson('/api/profile')->assertJsonPath('data.can_publish_events', false);
        $this->postJson('/api/events', $event)->assertForbidden();
        $subscription = PublisherSubscription::factory()->create(['user_id' => $publisher->id]);
        $this->getJson('/api/profile')->assertJsonPath('data.can_publish_events', true);
        $this->postJson('/api/events', $event)->assertCreated();
        $subscription->update(['ends_at' => now()->subSecond()]);
        $this->postJson('/api/events', $event)->assertForbidden();
        $this->assertDatabaseCount('events', 1);
    }
}
