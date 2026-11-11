@extends('admin.layout')
@section('title', 'Overview')
@section('content')
<div class="intro"><div><h2>A clear view of DarEvent.</h2><p class="muted">Review your community, keep content in check and follow publisher activity.</p></div><span class="badge {{ $configured ? 'success' : 'warning' }}">SonicPesa {{ $configured ? 'configured' : 'not configured' }}</span></div>
<div class="metrics">@foreach($stats as $label=>$count)<article class="metric"><span class="muted">{{ $label }}</span><strong>{{ number_format($count) }}</strong></article>@endforeach</div>
<section class="panel"><div class="panel-heading"><div><p class="eyebrow">PUBLISHER BILLING</p><h2>Recent payments</h2></div><a href="{{ route('admin.payments') }}">View all →</a></div><div class="table-scroll"><table><thead><tr><th>Publisher</th><th>Plan</th><th>Amount</th><th>Status</th><th>Created</th></tr></thead><tbody>@forelse($payments as $payment)<tr><td>{{ $payment->user->username }}</td><td>{{ $payment->plan->name }}</td><td>{{ number_format((float)$payment->amount,2) }} {{ $payment->currency }}</td><td><span class="badge">{{ $payment->status }}</span></td><td>{{ $payment->created_at->format('d M Y') }}</td></tr>@empty<tr><td colspan="5" class="empty">Payments will appear here when publishers choose a plan.</td></tr>@endforelse</tbody></table></div></section>
@endsection
