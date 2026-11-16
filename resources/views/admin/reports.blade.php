@extends('admin.layout')
@section('title', 'Content reports')
@section('content')
<div class="intro"><p class="muted">Review reported posts and comments. A report alone does not remove content or ban an account.</p><span class="badge warning">{{ $pending }} pending</span></div>
<form method="get" class="panel form-grid">
<label>Status<select name="status">@foreach(['pending'=>'Pending','action_taken'=>'Action taken','dismissed'=>'Dismissed','all'=>'All reports'] as $value=>$label)<option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>@endforeach</select></label>
<label>Content<select name="type"><option value="">Posts and comments</option><option value="event" @selected($type === 'event')>Posts</option><option value="comment" @selected($type === 'comment')>Comments and replies</option></select></label>
<label>Search<input name="q" maxlength="100" value="{{ $q }}" placeholder="Username or report details"></label><button class="button">Filter reports</button>
</form>
@forelse($reports as $report)
<section class="panel">
<div class="panel-heading"><h2>Report #{{ $report->id }} · {{ $report->target_type === 'event' ? 'Post' : 'Comment' }} #{{ $report->target_id }}</h2><span class="badge {{ $report->status === 'pending' ? 'warning' : '' }}">{{ str_replace('_', ' ', $report->status) }}</span></div>
<p class="small muted">Reported {{ $report->created_at->format('d M Y H:i') }} by {{ $report->reporter?->username ?? 'Deleted account' }}</p>
<p><strong>{{ \App\Models\ContentReport::REASONS[$report->reason] ?? $report->reason }}</strong></p>
@if($report->details)<p class="body-copy">{{ $report->details }}</p>@endif
<div class="report-evidence"><p class="eyebrow">Content when reported</p><h3>{{ $report->snapshot['title'] ?? 'Event no longer available' }}</h3><p class="small">Publisher: {{ $report->snapshot['author'] ?? 'Unknown' }}</p><p class="body-copy">{{ $report->snapshot['body'] ?? '' }}</p>
@if($report->target_type === 'event' && !empty($report->snapshot['image']))<a href="{{ $report->snapshot['image'] }}" target="_blank" rel="noopener noreferrer">Open reported photo ↗</a>@endif
@if($report->target_type === 'event' && !empty($report->snapshot['video']))<p><a href="{{ $report->snapshot['video'] }}" target="_blank" rel="noopener noreferrer">Open reported video ↗</a></p>@endif
</div>
<details><summary>Current content and account</summary>
@if($report->target_type === 'event')<p>{{ $report->event ? ($report->event->archived_at ? 'Post is hidden.' : 'Post is published.') : 'Post has been deleted.' }}</p>@if($report->event)<h3>{{ $report->event->title }}</h3><p class="body-copy">{{ $report->event->description }}</p>@endif
@else<p>{{ $report->comment ? 'Comment is still available.' : 'Comment has been removed.' }}</p>@if($report->comment)<p class="body-copy">{{ $report->comment->body }}</p>@endif @endif
@if($report->author)<a href="{{ route('admin.users', ['q' => $report->author->username]) }}">Manage {{ $report->author->username }} →</a>@endif
</details>
@if($report->status === 'pending')
<form method="post" action="{{ route('admin.reports.review', $report) }}" class="report-review">@csrf @method('PATCH')
<label>Decision reason<textarea name="decision_reason" required minlength="3" maxlength="2000" placeholder="Explain your review decision"></textarea></label>
<div class="button-row"><button class="button secondary" name="action" value="dismiss">Dismiss report</button><button class="button danger" name="action" value="remove" onclick="return confirm('Remove this content and record your decision? Removing a comment also removes its replies.')">{{ $report->target_type === 'event' ? 'Remove post' : 'Remove comment' }}</button></div>
</form>
@else<p class="small muted">Reviewed by {{ $report->reviewer?->username ?? 'Deleted administrator' }} · {{ $report->reviewed_at?->format('d M Y H:i') }}</p><p class="body-copy">{{ $report->decision_reason }}</p>@endif
</section>
@empty<div class="panel empty">No reports match these filters.</div>@endforelse
@include('admin.pagination', ['items' => $reports])
@endsection
