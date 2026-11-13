<?php

namespace App\Support;

use App\Models\PublisherPayment;
use App\Models\PublisherPlan;
use App\Models\PublisherSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubscriptionPayments
{
    public function __construct(private SonicPesa $gateway) {}

    public function checkout(User $user, PublisherPlan $plan, string $phone): PublisherPayment
    {
        if (! $user->email) {
            throw ValidationException::withMessages(['email' => 'Add an email in the app under Settings → Personal information before paying for a publisher plan.']);
        }
        abort_unless($user->verified && $plan->is_active, 403);
        abort_unless($this->gateway->configured(), 503, 'Online payments are not configured yet.');
        [$payment, $created] = DB::transaction(function () use ($user, $plan, $phone): array {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $current = PublisherSubscription::where('user_id', $user->id)->where('status', 'active')->where('ends_at', '>', now())->first();
            if ($current && $current->publisher_plan_id !== $plan->id) {
                throw ValidationException::withMessages(['plan' => 'Your current plan is still active. Renew the same plan, or contact support before changing plans.']);
            }
            $existing = PublisherPayment::where('user_id', $user->id)->whereIn('status', ['initializing', 'pending', 'unknown'])->latest()->first();
            if ($existing) {
                return [$existing, false];
            }
            $payment = PublisherPayment::create([
                'user_id' => $user->id, 'publisher_plan_id' => $plan->id,
                'reference' => (string) Str::uuid(), 'amount' => $plan->price, 'currency' => $plan->currency,
                'duration_days' => $plan->duration_days, 'phone' => $phone, 'status' => 'initializing',
            ]);

            return [$payment, true];
        });
        if (! $created) {
            return $payment;
        }
        try {
            $result = $this->gateway->createOrder([
                'buyer_email' => $user->email, 'buyer_name' => $user->name,
                'buyer_phone' => $phone, 'amount' => (float) $payment->amount, 'currency' => $payment->currency,
            ]);
            if (! is_string($result['order_id'] ?? null) || strlen($result['order_id']) > 150 || $result['order_id'] === '') {
                throw new \RuntimeException('Payment confirmation is incomplete. Contact support before trying again.');
            }
            $payment->update(['provider_order_id' => $result['order_id'], 'status' => 'pending']);
        } catch (\Throwable $exception) {
            $payment->update(['status' => 'unknown']);
            report($exception);
        }

        return $payment->fresh();
    }

    private function cents(mixed $amount): ?int
    {
        if (! is_scalar($amount) || ! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', (string) $amount)) {
            return null;
        }
        $parts = explode('.', (string) $amount);

        return ((int) $parts[0]) * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    public function verify(PublisherPayment $payment): PublisherPayment
    {
        if ($payment->status === 'paid' || ! $payment->provider_order_id) {
            return $payment;
        }
        $result = $this->gateway->orderStatus($payment->provider_order_id);
        if (($result['order_id'] ?? null) !== $payment->provider_order_id) {
            throw ValidationException::withMessages(['payment' => 'The payment reference could not be verified.']);
        }
        if (strtoupper($result['payment_status'] ?? $result['status'] ?? '') !== 'SUCCESS') {
            if (in_array(strtoupper($result['payment_status'] ?? $result['status'] ?? ''), ['CANCELLED', 'USERCANCELLED', 'REJECTED'], true)) {
                PublisherPayment::whereKey($payment->id)->where('status', '!=', 'paid')->update(['status' => 'failed']);
            }

            return $payment->fresh();
        }
        if ($this->cents($result['amount'] ?? null) !== $this->cents($payment->amount)
            || ($result['currency'] ?? null) !== $payment->currency || ! is_scalar($result['transid'] ?? null) || strlen((string) $result['transid']) > 150 || (string) $result['transid'] === '') {
            throw ValidationException::withMessages(['payment' => 'The payment amount, currency or transaction could not be verified.']);
        }

        $payer = $result['phone'] ?? $result['msisdn'] ?? null;
        if (! is_scalar($payer) || preg_replace('/[\s+\-()]/', '', (string) $payer) !== $payment->phone) {
            throw ValidationException::withMessages(['payment' => 'The payment phone number could not be verified.']);
        }

        return DB::transaction(function () use ($payment, $result): PublisherPayment {
            $locked = PublisherPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'paid') {
                return $locked;
            }
            if (PublisherPayment::where('provider_transaction_id', (string) $result['transid'])->where('id', '!=', $locked->id)->exists()) {
                throw ValidationException::withMessages(['payment' => 'This provider transaction has already been applied to another payment.']);
            }
            User::whereKey($locked->user_id)->lockForUpdate()->firstOrFail();
            $subscription = PublisherSubscription::where('user_id', $locked->user_id)->lockForUpdate()->first();
            $start = $subscription?->status === 'active' && $subscription->ends_at->isFuture() ? $subscription->ends_at->copy() : now();
            PublisherSubscription::updateOrCreate(['user_id' => $locked->user_id], [
                'publisher_plan_id' => $locked->publisher_plan_id, 'status' => 'active',
                'starts_at' => $subscription?->status === 'active' && $subscription->ends_at->isFuture() ? $subscription->starts_at : now(),
                'ends_at' => $start->addDays($locked->duration_days),
            ]);
            $locked->update(['status' => 'paid', 'paid_at' => now(), 'provider_transaction_id' => (string) $result['transid']]);

            return $locked;
        });
    }
}
