<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_returns_matching_events_and_users(): void
    {
        $host = User::factory()->create([
            'name' => 'DJ Khaled',
            'username' => 'dj_khaled',
        ]);
        Event::factory()->create([
            'user_id' => $host->id,
            'title' => 'Summer Beach Party',
        ]);
        Event::factory()->create(['title' => 'Campus Hackathon']);

        $this->getJson('/api/search?q=Khaled')
            ->assertOk()
            ->assertJsonPath('meta.users_count', 1)
            ->assertJsonPath('meta.events_count', 1);

        $this->getJson('/api/search?q=Beach&type=events')
            ->assertOk()
            ->assertJsonPath('meta.events_count', 1)
            ->assertJsonPath('meta.users_count', 0);
    }

    public function test_recent_searches_are_saved_removed_and_cleared(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/recent-searches', [
            'query' => 'AfroBeats Live Tour',
            'type' => 'music',
            'subtitle' => 'Music Event',
        ])->assertCreated();

        $search = $user->recentSearches()->firstOrFail();

        $this->getJson('/api/recent-searches')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.query', 'AfroBeats Live Tour');

        $this->deleteJson('/api/recent-searches/'.$search->id)->assertOk();
        $this->postJson('/api/recent-searches', ['query' => 'Another']);
        $this->deleteJson('/api/recent-searches')->assertOk();

        $this->assertDatabaseCount('recent_searches', 0);
    }

    public function test_a_verification_request_can_be_submitted_once(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $payload = [
            'account_type' => 'ngo',
            'document' => UploadedFile::fake()->create('license.pdf', 100, 'application/pdf'),
            'paid' => true,
            'payment_reference' => 'PAY-123',
        ];

        $this->postJson('/api/verification-requests', $payload)
            ->assertCreated()
            ->assertJsonPath('data.account_type', 'ngo')
            ->assertJsonPath('data.payment_status', 'paid');

        $this->postJson('/api/verification-requests', $payload)
            ->assertUnprocessable();

        $this->getJson('/api/verification-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_tickets_can_be_booked_and_cancelled(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['price' => 25]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/events/'.$event->slug.'/tickets', [
            'quantity' => 2,
        ])->assertCreated()
            ->assertJsonPath('data.quantity', 2)
            ->assertJsonPath('data.total', 50);

        $ticketId = $response->json('data.id');

        $this->getJson('/api/tickets')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->postJson('/api/tickets/'.$ticketId.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_story_highlights_can_be_created_and_listed(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/story-highlights', [
            'label' => 'Parties',
            'icon' => 'musical-notes',
        ])->assertCreated();

        $this->getJson('/api/story-highlights')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.label', 'Parties');
    }

    public function test_notifications_can_be_listed_and_marked_as_read(): void
    {
        $user = User::factory()->create();
        $user->notify(new AppNotification('follow', ['message' => 'Someone followed you']));

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.unread_count', 1);

        $notificationId = $response->json('data.0.id');

        $this->postJson('/api/notifications/'.$notificationId.'/read')->assertOk();
        $this->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);
    }
}
