<?php

namespace App\Http\Controllers;

use App\Models\PublisherPayment;
use App\Models\PublisherPlan;
use App\Models\PublisherSubscription;
use App\Support\SonicPesa;
use App\Support\SubscriptionPayments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BillingController extends Controller
{
    public function index(Request $request, SonicPesa $gateway): View
    {
        return view('billing.index', [
            'plans' => PublisherPlan::where('is_active', true)->orderBy('price')->get(),
            'subscription' => PublisherSubscription::with('plan')->where('user_id', $request->user()->id)->first(),
            'payments' => PublisherPayment::with('plan')->where('user_id', $request->user()->id)->latest()->paginate(15),
            'configured' => $gateway->configured(),
        ]);
    }

    public function checkout(Request $request, PublisherPlan $publisherPlan, SubscriptionPayments $payments): RedirectResponse
    {
        $request->merge(['phone' => preg_replace('/[\s+\-()]/', '', (string) $request->input('phone'))]);
        $data = $request->validate(['phone' => ['required', 'regex:/^255[67]\d{8}$/D'], 'quoted_price' => ['required', 'numeric', 'decimal:0,2'], 'quoted_duration' => ['required', 'integer'], 'accept_policies' => ['accepted']]);
        if (number_format((float) $data['quoted_price'], 2, '.', '') !== $publisherPlan->price || (int) $data['quoted_duration'] !== $publisherPlan->duration_days) {
            throw ValidationException::withMessages(['plan' => 'This plan has changed. Refresh the page to confirm its current price and duration.']);
        }
        $request->user()->forceFill(['terms_accepted_at' => now(), 'terms_version' => config('legal.terms.version'), 'privacy_policy_version' => config('legal.privacy.version')])->save();
        $payment = $payments->checkout($request->user(), $publisherPlan, $data['phone']);

        return redirect()->route('billing.payment', $payment);
    }

    public function show(Request $request, PublisherPayment $publisherPayment): View
    {
        abort_unless($publisherPayment->user_id === $request->user()->id, 404);

        return view('billing.payment', ['payment' => $publisherPayment->load('plan')]);
    }

    public function check(Request $request, PublisherPayment $publisherPayment, SubscriptionPayments $payments): RedirectResponse|JsonResponse
    {
        abort_unless($publisherPayment->user_id === $request->user()->id, 404);
        try {
            $payment = $payments->verify($publisherPayment);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);

            return $request->expectsJson() ? response()->json(['message' => 'Payment status is temporarily unavailable.'], 503) : back()->withErrors(['payment' => 'Payment status is temporarily unavailable. Please check again shortly.']);
        }

        return $request->expectsJson() ? response()->json(['status' => $payment->status]) : back()->with('status', $payment->status === 'paid' ? 'Payment verified. Your subscription is active.' : 'Payment status: '.$payment->status.'.');
    }

    public function webhook(Request $request, SubscriptionPayments $payments): Response
    {
        $secret = config('services.sonicpesa.secret');
        abort_unless(is_string($secret) && $secret !== '' && hash_equals(hash_hmac('sha256', $request->getContent(), $secret), (string) $request->header('X-SonicPesa-Signature')), 403);
        $data = $request->validate(['order_id' => ['required', 'string', 'max:150']]);
        $payment = PublisherPayment::where('provider_order_id', $data['order_id'])->firstOrFail();
        $payments->verify($payment);

        return response()->noContent();
    }
}
