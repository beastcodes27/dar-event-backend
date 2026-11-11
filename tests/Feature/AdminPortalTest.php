<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\PublisherPlan;
use App\Models\PublisherSubscription;
use App\Models\Story;
use App\Models\User;
use App\Models\VerificationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPortalTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();

        return $user;
    }

    public function test_production_web_login_redirects_to_https(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->get('http://localhost/admin/login')->assertRedirect('https://localhost/admin/login');
    }

    public function test_guests_are_sent_to_login_and_non_admins_cannot_access_management(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/subscriptions')->assertRedirect('/account/login');
        $this->actingAs(User::factory()->create(), 'web');
        $this->get('/admin')->assertForbidden();
        $this->post('/admin/plans', ['name' => 'Unauthorized'])->assertForbidden();
        $this->assertDatabaseCount('publisher_plans', 0);
    }

    public function test_admin_login_uses_existing_credentials_and_rejects_regular_users(): void
    {
        $regular = User::factory()->create();
        $this->post('/admin/login', ['login' => $regular->username, 'password' => 'password'])->assertSessionHasErrors('login');
        $this->assertGuest();
        $admin = $this->admin();

        $this->post('/admin/login', ['login' => $admin->email, 'password' => 'password'])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($admin);
        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $this->assertGuest();
    }

    public function test_administrators_can_load_all_management_pages_and_edit_plans(): void
    {
        $this->actingAs($this->admin(), 'web');
        $this->get('/admin')->assertOk()->assertSee('Overview');
        foreach (['users', 'events', 'stories', 'verification', 'support', 'plans', 'subscriptions', 'payments'] as $section) {
            $this->get('/admin/'.$section)->assertOk();
        }
        $data = ['name' => 'Monthly publisher', 'price' => '10000.00', 'currency' => 'TZS', 'duration_days' => 30, 'description' => 'Publish events', 'is_active' => true];
        $this->post('/admin/plans', $data)->assertRedirect()->assertSessionHasNoErrors();
        $plan = PublisherPlan::firstOrFail();
        $this->assertDatabaseHas('publisher_plans', ['id' => $plan->id, 'name' => 'Monthly publisher', 'price' => 10000, 'duration_days' => 30]);
        $this->patch('/admin/plans/'.$plan->id, $data + ['unknown' => true])->assertSessionHasNoErrors();
        $this->patch('/admin/plans/'.$plan->id, array_replace($data, ['is_active' => false, 'price' => '15000.00']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('publisher_plans', ['id' => $plan->id, 'is_active' => false, 'price' => 15000]);
    }

    public function test_plan_validation_rejects_invalid_prices_and_durations(): void
    {
        $this->actingAs($this->admin(), 'web');

        $this->post('/admin/plans', ['name' => 'Invalid', 'price' => -1, 'duration_days' => 0, 'currency' => 'USD', 'is_active' => true])->assertSessionHasErrors(['price', 'duration_days', 'currency']);
        $this->assertDatabaseCount('publisher_plans', 0);
    }

    public function test_verification_reviews_protect_documents_and_do_not_create_a_paid_plan(): void
    {
        $this->freezeTime();
        Storage::fake('public');
        Storage::disk('public')->put('verifications/application.pdf', 'A supporting document');
        $applicant = User::factory()->create();
        $application = VerificationRequest::create(['user_id' => $applicant->id, 'account_type' => 'company', 'document_path' => 'verifications/application.pdf', 'status' => 'pending']);
        $this->actingAs($applicant, 'web');
        $this->get('/admin/verification/'.$application->id.'/document')->assertForbidden();
        $this->actingAs($this->admin(), 'web');
        $this->get('/admin/verification/'.$application->id.'/document')->assertDownload('verification-'.$application->id.'.pdf');

        $this->patch('/admin/verification/'.$application->id, ['status' => 'approved', 'notes' => 'Documents checked'])->assertRedirect();
        $this->assertDatabaseHas('verification_requests', ['id' => $application->id, 'status' => 'approved', 'notes' => 'Documents checked']);
        $this->assertDatabaseHas('users', ['id' => $applicant->id, 'verified' => true]);
        $this->assertDatabaseCount('publisher_subscriptions', 0);
        $this->patch('/admin/verification/'.$application->id, ['status' => 'rejected'])->assertConflict();
    }

    public function test_publisher_cannot_reverse_administrator_event_moderation(): void
    {
        $this->freezeTime();
        $owner = User::factory()->publisher()->create();
        $event = Event::factory()->create(['user_id' => $owner->id]);
        $this->actingAs($this->admin(), 'web');

        $this->patch('/admin/events/'.$event->id.'/archive', ['archived' => true])->assertRedirect();
        $this->assertDatabaseHas('events', ['id' => $event->id, 'moderated_at' => now(), 'archived_at' => now()]);
        Sanctum::actingAs($owner);
        $this->patchJson('/api/settings/posts/'.$event->id.'/archive', ['archived' => false])->assertForbidden();
    }

    public function test_admin_can_reply_to_support_remove_stories_and_cancel_subscriptions(): void
    {
        $this->freezeTime();
        $user = User::factory()->publisher()->create();
        $story = Story::create(['user_id' => $user->id, 'caption' => 'A story', 'expires_at' => now()->addDay()]);
        $supportId = DB::table('support_requests')->insertGetId(['user_id' => $user->id, 'subject' => 'Help', 'body' => 'Please help', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $subscription = PublisherSubscription::where('user_id', $user->id)->firstOrFail();
        $this->actingAs($this->admin(), 'web');

        $this->patch('/admin/support/'.$supportId, ['admin_reply' => 'We can help.', 'status' => 'resolved'])->assertRedirect();
        $this->assertDatabaseHas('support_requests', ['id' => $supportId, 'admin_reply' => 'We can help.', 'status' => 'resolved']);
        $this->delete('/admin/stories/'.$story->id)->assertRedirect();
        $this->assertModelMissing($story);
        $this->patch('/admin/subscriptions/'.$subscription->id.'/cancel')->assertRedirect();
        $this->assertDatabaseHas('publisher_subscriptions', ['id' => $subscription->id, 'status' => 'cancelled']);
    }

    public function test_admin_user_updates_do_not_accept_role_escalation_and_rendered_content_is_escaped(): void
    {
        $user = User::factory()->create(['name' => '<script>alert(1)</script>']);
        $this->actingAs($this->admin(), 'web');

        $this->patch('/admin/users/'.$user->id.'/verification', ['verified' => true, 'is_admin' => true])->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_admin' => false, 'verified' => true]);
        $this->get('/admin/users')->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }
}
