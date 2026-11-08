<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventShareTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_shares_increment_a_persisted_counter_and_ignore_supplied_totals(): void
    {
        $event = Event::factory()->create();
        $this->postJson('/api/events/'.$event->id.'/share')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/events/'.$event->id)->assertJsonPath('data.shares_count', 0);
        $this->postJson('/api/events/'.$event->id.'/share', ['shares_count' => 100000])->assertOk()->assertJsonPath('shares_count', 1);
        $this->postJson('/api/events/'.$event->id.'/share')->assertJsonPath('shares_count', 2);
        $this->getJson('/api/feed')->assertJsonPath('data.0.shares_count', 2);
        $this->assertSame(2, $event->fresh()->shares_count);
    }

    public function test_private_archived_and_blocked_events_cannot_be_shared_by_other_accounts(): void
    {
        $event = Event::factory()->private()->create();
        $viewer = User::factory()->create();
        Sanctum::actingAs($viewer);
        $this->postJson('/api/events/'.$event->id.'/share')->assertForbidden();
        Sanctum::actingAs($event->host);
        $this->postJson('/api/events/'.$event->id.'/share')->assertOk();
        $event->update(['is_public' => true]);
        $this->postJson('/api/settings/blocked-accounts/'.$viewer->username)->assertOk();
        Sanctum::actingAs($viewer);
        $this->postJson('/api/events/'.$event->id.'/share')->assertNotFound();
        Sanctum::actingAs($event->host);
        $this->patchJson('/api/settings/posts/'.$event->id.'/archive', ['archived' => true])->assertOk();
        $this->postJson('/api/events/'.$event->id.'/share')->assertNotFound();
        $this->assertSame(1, $event->fresh()->shares_count);
    }

    public function test_comment_counts_include_replies_and_refresh_after_deletion(): void
    {
        $event = Event::factory()->create();
        Sanctum::actingAs(User::factory()->create());
        $id = $this->postJson('/api/events/'.$event->id.'/comments', ['body' => 'First'])->assertCreated()->json('data.id');
        $this->postJson('/api/events/'.$event->id.'/comments', ['body' => 'Reply', 'parent_id' => $id])->assertCreated();
        $this->getJson('/api/events/'.$event->id)->assertJsonPath('data.comments_count', 2);
        $this->getJson('/api/events/'.$event->id.'/comments')->assertJsonPath('meta.comments_count', 2);
        $this->deleteJson('/api/comments/'.$id)->assertOk();
        $this->getJson('/api/events/'.$event->id.'/comments')->assertJsonPath('meta.comments_count', 0);

    }
}
