<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\ContentReport;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ContentReportController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['status' => ['nullable', 'in:pending,action_taken,dismissed,all'], 'type' => ['nullable', 'in:event,comment'], 'q' => ['nullable', 'string', 'max:100']]);
        $status = $filters['status'] ?? 'pending';
        $type = $filters['type'] ?? '';
        $q = $filters['q'] ?? '';
        $reports = ContentReport::with(['reporter', 'author', 'reviewer', 'event', 'comment'])->when($status !== 'all', fn ($query) => $query->where('status', $status))->when($type, fn ($query) => $query->where('target_type', $type))->when($q, fn ($query) => $query->where(fn ($query) => $query->where('details', 'like', '%'.$q.'%')->orWhereHas('author', fn ($query) => $query->where('username', 'like', '%'.$q.'%'))->orWhereHas('reporter', fn ($query) => $query->where('username', 'like', '%'.$q.'%'))))->oldest()->paginate(20)->withQueryString();
        $pending = ContentReport::where('status', 'pending')->count();

        return view('admin.reports', compact('reports', 'status', 'type', 'q', 'pending'));
    }

    public function review(Request $request, ContentReport $contentReport): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', 'in:dismiss,remove'], 'decision_reason' => ['required', 'string', 'min:3', 'max:2000']]);
        DB::transaction(function () use ($request, $contentReport, $data): void {
            $report = ContentReport::whereKey($contentReport->id)->lockForUpdate()->firstOrFail();
            abort_unless($report->status === 'pending', 409, 'This report has already been reviewed.');
            if ($data['action'] === 'remove') {
                $event = Event::withoutGlobalScopes()->whereKey($report->event_id)->lockForUpdate()->first();
                if ($report->target_type === 'event') {
                    if ($event) {
                        $event->forceFill(['archived_at' => now(), 'moderated_at' => now()])->save();
                    }
                } else {
                    $comment = Comment::whereKey($report->comment_id)->lockForUpdate()->first();
                    if ($comment) {
                        $comment->delete();
                    }
                    if ($event) {
                        $event->update(['comments_count' => $event->comments()->count()]);
                    }
                }
            }
            $report->forceFill(['status' => $data['action'] === 'remove' ? 'action_taken' : 'dismissed', 'decision_reason' => $data['decision_reason'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()])->save();
        });

        return back()->with('status', 'Report reviewed. Your decision has been recorded.');
    }
}
