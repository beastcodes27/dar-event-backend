<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventTest extends TestCase
{
    use RefreshDatabase;

    public function test_events_can_be_listed_and_filtered(): void
    {
        $music = Event::factory()->create([
            'title' => 'Beach Party',
            'category' => 'music',
            'location' => 'Miami Beach',
        ]);
        Event::factory()->create([
            'title' => 'Campus Night',
            'category' => 'university',
        ]);

        $this->getJson('/api/events')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/events?category=music')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $music->id);

        $this->getJson('/api/events?q=Beach')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_an_event_can_be_viewed_by_slug(): void
    {
        $event = Event::factory()->create(['slug' => 'summer-beach-party']);

        $this->getJson('/api/events/summer-beach-party')
            ->assertOk()
            ->assertJsonPath('data.title', $event->title)
            ->assertJsonPath('data.host.id', $event->user_id);
    }

    public function test_public_events_expose_viewer_specific_flags_when_authenticated(): void
    {
        $event = Event::factory()->create();
        $user = User::factory()->create();
        $event->likes()->attach($user->id);
        $event->savedBy()->attach($user->id);

        Sanctum::actingAs($user);

        $this->getJson('/api/events/'.$event->slug)
            ->assertOk()
            ->assertJsonPath('data.liked', true)
            ->assertJsonPath('data.saved', true);
    }

    public function test_private_events_are_hidden_from_other_users(): void
    {
        $event = Event::factory()->private()->create();
        $other = User::factory()->create();

        $this->getJson('/api/events/'.$event->slug)->assertNotFound();

        Sanctum::actingAs($other);
        $this->getJson('/api/events/'.$event->slug)->assertNotFound();
    }

    public function test_a_verified_user_can_create_update_and_delete_an_event(): void
    {
        $user = User::factory()->verified()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/events', [
            'title' => 'My Rooftop Party',
            'description' => 'Good vibes only.',
            'category' => 'music',
            'location' => 'Dar es Salaam',
            'starts_at' => now()->addWeek()->toIso8601String(),
            'price' => 25,
            'currency' => 'usd',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.slug', 'my-rooftop-party')
            ->assertJsonPath('data.currency', 'USD');

        $event = Event::where('slug', 'my-rooftop-party')->firstOrFail();

        $this->patchJson('/api/events/'.$event->slug, [
            'title' => 'My Rooftop Party Reloaded',
        ])->assertOk()
            ->assertJsonPath('data.title', 'My Rooftop Party Reloaded');

        $this->deleteJson('/api/events/'.$event->id)->assertOk();
        $this->assertDatabaseMissing('events', ['id' => $event->id]);
    }

    public function test_only_the_host_can_update_or_delete_an_event(): void
    {
        $event = Event::factory()->create();
        $other = User::factory()->verified()->create();
        Sanctum::actingAs($other);

        $this->patchJson('/api/events/'.$event->slug, ['title' => 'Hijacked'])
            ->assertForbidden();

        $this->deleteJson('/api/events/'.$event->slug)->assertForbidden();
    }

    public function test_unverified_accounts_cannot_publish_or_claim_verification_in_the_payload(): void
    {
        $user = User::factory()->create();
        $payload = ['title' => 'Unverified event', 'category' => 'music', 'location' => 'Dar', 'starts_at' => now()->addDay()->toIso8601String(), 'verified' => true];
        $this->postJson('/api/events', $payload)->assertUnauthorized();
        Sanctum::actingAs($user);
        $this->postJson('/api/events', $payload)->assertForbidden()
            ->assertJsonPath('message', 'Only verified accounts can publish events. Apply for verification from your profile.');
        $this->postJson('/api/events', $payload + ['is_public' => false])->assertForbidden();
        $this->patchJson('/api/profile', ['verified' => true, 'verified_at' => now()->toIso8601String()])->assertOk();
        $this->assertFalse($user->fresh()->verified);
        $this->postJson('/api/events', $payload)->assertForbidden();
        $this->assertDatabaseCount('events', 0);
    }

    public function test_losing_verification_blocks_edits_video_uploads_and_restores_but_allows_removal(): void
    {
        $user = User::factory()->verified()->create();
        $event = Event::factory()->private()->create(['user_id' => $user->id]);
        $user->update(['verified' => false]);
        Sanctum::actingAs($user);
        $this->patchJson('/api/events/'.$event->id, ['is_public' => true])->assertForbidden();
        $this->patchJson('/api/events/'.$event->id, ['title' => 'Changed'])->assertForbidden();
        $this->postJson('/api/events/'.$event->id.'/video', [])->assertForbidden();
        $this->deleteJson('/api/events/'.$event->id.'/video')->assertOk();
        $this->patchJson('/api/settings/posts/'.$event->id.'/archive', ['archived' => true])->assertOk();
        $this->patchJson('/api/settings/posts/'.$event->id.'/archive', ['archived' => false])->assertForbidden();
        $this->assertNotNull($event->fresh()->archived_at);
        $user->update(['verified' => true]);
        $this->patchJson('/api/settings/posts/'.$event->id.'/archive', ['archived' => false])->assertOk();
        $user->update(['verified' => false]);
        $this->deleteJson('/api/events/'.$event->id)->assertOk();
    }

    public function test_my_events_and_feed_return_the_expected_events(): void
    {
        $user = User::factory()->create();
        $followed = User::factory()->create();
        $user->following()->attach($followed->id);

        $own = Event::factory()->create(['user_id' => $user->id]);
        $followedEvent = Event::factory()->create(['user_id' => $followed->id]);
        Event::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/my-events')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id);

        $this->getJson('/api/feed')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_discovery_feed_includes_unfollowed_publishers_with_stable_pagination_and_viewer_flags(): void
    {
        $viewer = User::factory()->create();
        $older = Event::factory()->create(['created_at' => now()->subHour()]);
        $newer = Event::factory()->create(['created_at' => now()]);
        $viewer->likedEvents()->attach($newer->id);
        $viewer->savedEvents()->attach($newer->id);
        Sanctum::actingAs($viewer);
        $this->assertSame(0, $viewer->following()->count());
        $this->getJson('/api/feed?per_page=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $newer->id)->assertJsonPath('data.0.liked', true)->assertJsonPath('data.0.saved', true);
        $this->getJson('/api/feed?per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $older->id)->assertJsonPath('links.next', null);
        $this->getJson('/api/feed?per_page=51')->assertUnprocessable();
    }

    public function test_discovery_keeps_private_archived_and_blocked_events_out_of_other_users_feeds(): void
    {
        $viewer = User::factory()->create();
        $public = Event::factory()->create();
        $own = Event::factory()->private()->create(['user_id' => $viewer->id]);
        Event::factory()->private()->create();
        $archived = Event::factory()->create();
        $archived->forceFill(['archived_at' => now()])->save();
        $blocked = Event::factory()->create();
        Sanctum::actingAs($viewer);
        $this->postJson('/api/settings/blocked-accounts/'.$blocked->host->username)->assertOk();
        $result = $this->getJson('/api/feed')->assertOk()->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing([$public->id, $own->id], collect($result->json('data'))->pluck('id')->all());
    }
}
