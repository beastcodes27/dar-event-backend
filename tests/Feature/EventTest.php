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

    public function test_a_user_can_create_update_and_delete_an_event(): void
    {
        $user = User::factory()->create();
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
        $other = User::factory()->create();
        Sanctum::actingAs($other);

        $this->patchJson('/api/events/'.$event->slug, ['title' => 'Hijacked'])
            ->assertForbidden();

        $this->deleteJson('/api/events/'.$event->slug)->assertForbidden();
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
            ->assertJsonCount(2, 'data');
    }
}
