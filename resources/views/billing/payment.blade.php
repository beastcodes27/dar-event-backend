@extends('billing.layout')
@section('title', $payment->status==='paid' ? 'Payment confirmed.' : 'Complete your payment')
@section('content')
<section class="panel payment-card"><span class="badge {{ $payment->status==='paid' ? 'success' : 'warning' }}">{{ ucfirst($payment->status) }}</span><h2>{{ $payment->plan->name }}</h2><p class="plan-price">{{ number_format((float)$payment->amount,2) }} <small>{{ $payment->currency }}</small></p>
@if($payment->status==='paid')<p>Your payment is verified and your publisher subscription is active. You can return to Veylo to publish.</p>
@elseif($payment->status==='unknown' || $payment->status==='initializing')<p>The payment request could not be confirmed yet. Check your mobile-money account or contact Veylo support before starting another payment. Do not approve a second payment request.</p>
@elseif($payment->status==='failed')<p>This payment was cancelled or declined. You can return to plans and try again.</p>
@else<p>Approve the SonicPesa payment prompt on <strong>{{ $payment->phone }}</strong>. Your plan activates only after the payment is verified.</p>@endif
<p class="muted small">Payment reference: {{ $payment->reference }}</p>
@if($payment->provider_order_id && !in_array($payment->status,['paid','failed']))<form method="post" action="{{ route('billing.check',$payment) }}">@csrf<button class="button">Check payment status</button></form>@endif
<a class="button secondary" href="{{ route('billing.index') }}">Back to publisher plans</a></section>
@if($payment->provider_order_id && $payment->status==='pending')<div data-payment-check="{{ route('billing.check',$payment) }}"><p data-payment-progress class="muted" role="status">Waiting for mobile-money approval…</p></div><script src="{{ asset('billing.js') }}" defer></script>@endif
@endsection
