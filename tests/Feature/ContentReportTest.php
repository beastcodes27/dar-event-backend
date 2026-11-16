<?php

namespace Tests\Feature;

use App\Models\ContentReport;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContentReportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    public function test_signed_in_users_submit_reports_once_and_cannot_forge_review_fields(): void
    {
        $event = Event::factory()->create(['description' => 'Original event description', 'video_url' => 'https://example.com/reported-event.mp4']);
        $reporter = User::factory()->create();
        $this->postJson('/api/events/'.$event->id.'/report', ['reason' => 'spam'])->assertUnauthorized();
        Sanctum::actingAs($reporter);
        $this->postJson('/api/events/'.$event->id.'/report', ['reason' => 'spam', 'details' => 'Repeated scam advertisement', 'status' => 'dismissed', 'reporter_id' => $event->user_id])->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->postJson('/api/events/'.$event->id.'/report', ['reason' => 'harassment'])->assertOk();
        $this->assertDatabaseCount('content_reports', 1);
        $report = ContentReport::first();
        $this->assertSame($reporter->id, $report->reporter_id);
        $this->assertSame('Original event description', $report->snapshot['body']);
        $this->assertSame('https://example.com/reported-event.mp4', $report->snapshot['video']);
        $this->assertSame('spam', $report->reason);
        $this->assertNull($report->reviewed_by);
    }

    public function test_reports_validate_reason_and_details_before_saving(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $event = Event::factory()->create();
        $this->postJson('/api/events/'.$event->id.'/report', ['reason' => 'not-a-reason', 'details' => str_repeat('x', 2001)])->assertUnprocessable()->assertJsonValidationErrors(['reason', 'details']);
        $this->assertDatabaseCount('content_reports', 0);
    }

    public function test_users_cannot_report_own_private_archived_or_blocked_posts(): void
    {
        $user = User::factory()->create();
        $own = Event::factory()->create(['user_id' => $user->id]);
        $private = Event::factory()->create(['is_public' => false]);
        $archived = Event::factory()->create();
        $archived->forceFill(['archived_at' => now()])->save();
        $blocked = Event::factory()->create();
        DB::table('account_blocks')->insert(['user_id' => $user->id, 'blocked_user_id' => $blocked->user_id, 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($user);
        $this->postJson('/api/events/'.$own->id.'/report', ['reason' => 'spam'])->assertUnprocessable();
        foreach ([$private, $archived, $blocked] as $event) {
            $this->postJson('/api/events/'.$event->id.'/report', ['reason' => 'spam'])->assertNotFound();
        }
        $this->assertDatabaseCount('content_reports', 0);
    }

    public function test_comments_and_replies_are_reportable_only_when_visible(): void
    {
        $event = Event::factory()->create();
        $author = User::factory()->create();
        $user = User::factory()->create();
        $root = $event->comments()->create(['user_id' => $author->id, 'body' => 'Root comment']);
        $reply = $event->comments()->create(['user_id' => $author->id, 'parent_id' => $root->id, 'body' => 'Reply evidence']);
        Sanctum::actingAs($user);
        $this->postJson('/api/comments/'.$reply->id.'/report', ['reason' => 'harassment'])->assertCreated();
        $this->assertSame('Reply evidence', ContentReport::first()->snapshot['body']);
        DB::table('account_blocks')->insert(['user_id' => $user->id, 'blocked_user_id' => $author->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/comments/'.$root->id.'/report', ['reason' => 'spam'])->assertNotFound();
        $this->assertDatabaseCount('content_reports', 1);
    }

    public function test_report_queue_and_decisions_are_restricted_to_administrators(): void
    {
        $report = ContentReport::factory()->create();
        $this->get('/admin/reports')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create(), 'web');
        $this->get('/admin/reports')->assertForbidden();
        $this->patch('/admin/reports/'.$report->id, ['action' => 'remove', 'decision_reason' => 'Unsafe content'])->assertForbidden();
        $this->assertSame('pending', $report->fresh()->status);
        $this->assertNull($report->event->archived_at);
    }

    public function test_admin_queue_filters_reports_and_escapes_reported_content(): void
    {
        $report = ContentReport::factory()->create(['details' => '<script>alert(1)</script>']);
        ContentReport::factory()->create(['status' => 'dismissed']);
        $this->actingAs($this->admin(), 'web')->get('/admin/reports')->assertSee('Content reports')->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertViewHas('reports', fn ($reports) => $reports->total() === 1);
        $this->get('/admin/reports?status=all&type=event')->assertViewHas('reports', fn ($reports) => $reports->total() === 2);
    }

    public function test_removing_a_reported_post_records_audit_and_prevents_publisher_restoration(): void
    {
        $report = ContentReport::factory()->create();
        $admin = $this->admin();
        $this->actingAs($admin, 'web')->patch('/admin/reports/'.$report->id, ['action' => 'remove', 'decision_reason' => 'Confirmed unsafe content'])->assertRedirect();
        $this->assertDatabaseHas('content_reports', ['id' => $report->id, 'status' => 'action_taken', 'reviewed_by' => $admin->id, 'decision_reason' => 'Confirmed unsafe content']);
        $event = Event::withoutGlobalScopes()->findOrFail($report->event_id);
        $this->assertNotNull($event->archived_at);
        $this->assertNotNull($event->moderated_at);
        $this->patch('/admin/reports/'.$report->id, ['action' => 'dismiss', 'decision_reason' => 'Different decision'])->assertConflict();
    }

    public function test_dismissal_keeps_content_and_requires_a_decision_reason(): void
    {
        $report = ContentReport::factory()->create();
        $this->actingAs($this->admin(), 'web');
        $this->patch('/admin/reports/'.$report->id, ['action' => 'dismiss'])->assertSessionHasErrors('decision_reason');
        $this->assertSame('pending', $report->fresh()->status);
        $this->patch('/admin/reports/'.$report->id, ['action' => 'dismiss', 'decision_reason' => 'No violation found'])->assertRedirect();
        $this->assertSame('dismissed', $report->fresh()->status);
        $this->assertNull($report->event->fresh()->archived_at);
    }

    public function test_removing_a_comment_removes_replies_updates_counts_and_keeps_report_evidence(): void
    {
        $event = Event::factory()->create(['comments_count' => 3]);
        $user = User::factory()->create();
        $root = $event->comments()->create(['user_id' => $user->id, 'body' => 'Reported comment']);
        $event->comments()->create(['user_id' => $user->id, 'parent_id' => $root->id, 'body' => 'Reply']);
        $event->comments()->create(['user_id' => $user->id, 'body' => 'Unrelated comment']);
        $report = ContentReport::factory()->create(['event_id' => $event->id, 'target_type' => 'comment', 'target_id' => $root->id, 'comment_id' => $root->id, 'snapshot' => ['body' => 'Reported comment']]);
        $this->actingAs($this->admin(), 'web')->patch('/admin/reports/'.$report->id, ['action' => 'remove', 'decision_reason' => 'Abusive comment'])->assertRedirect();
        $this->assertDatabaseMissing('comments', ['id' => $root->id]);
        $this->assertSame(1, $event->fresh()->comments_count);
        $this->assertSame('Reported comment', $report->fresh()->snapshot['body']);
        $this->assertNull($report->fresh()->comment_id);
    }

    public function test_reports_survive_deleted_content_and_can_still_be_reviewed(): void
    {
        $report = ContentReport::factory()->create();
        $report->event->delete();
        $this->actingAs($this->admin(), 'web')->get('/admin/reports')->assertSee('Post has been deleted.')->assertSee('Event information at submission');
        $this->patch('/admin/reports/'.$report->id, ['action' => 'remove', 'decision_reason' => 'Content already removed'])->assertRedirect();
        $this->assertSame('action_taken', $report->fresh()->status);
    }
}
