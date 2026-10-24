<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Event;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EngagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_like_and_unlike_an_event(): void
    {
        Notification::fake();

        $host = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $host->id]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/events/'.$event->slug.'/like')
            ->assertOk()
            ->assertJsonPath('liked', true)
            ->assertJsonPath('likes_count', 1);

        Notification::assertSentTo($host, AppNotification::class);

        $this->postJson('/api/events/'.$event->slug.'/like')
            ->assertOk()
            ->assertJsonPath('liked', false)
            ->assertJsonPath('likes_count', 0);
    }

    public function test_a_user_can_save_and_unsave_an_event(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/events/'.$event->slug.'/save')
            ->assertOk()
            ->assertJsonPath('saved', true);

        $this->getJson('/api/saved-events')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->postJson('/api/events/'.$event->slug.'/save')
            ->assertOk()
            ->assertJsonPath('saved', false);

        $this->getJson('/api/saved-events')->assertJsonCount(0, 'data');
    }

    public function test_comments_and_replies_can_be_created(): void
    {
        Notification::fake();

        $host = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $host->id]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $comment = $this->postJson('/api/events/'.$event->slug.'/comments', [
            'body' => 'This looks amazing!',
        ])->assertCreated()
            ->assertJsonPath('data.body', 'This looks amazing!')
            ->json('data');

        $this->postJson('/api/events/'.$event->slug.'/comments', [
            'body' => 'Replying to you',
            'parent_id' => $comment['id'],
        ])->assertCreated()
            ->assertJsonPath('data.body', 'Replying to you');

        $this->getJson('/api/events/'.$event->slug.'/comments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonCount(1, 'data.0.replies');

        $this->assertDatabaseHas('events', ['id' => $event->id, 'comments_count' => 2]);
        Notification::assertSentTo($host, AppNotification::class);
    }

    public function test_comment_author_and_event_host_can_delete_a_comment(): void
    {
        $host = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $host->id]);
        $author = User::factory()->create();
        $comment = Comment::create([
            'event_id' => $event->id,
            'user_id' => $author->id,
            'body' => 'Delete me',
        ]);

        Sanctum::actingAs($author);
        $this->deleteJson('/api/comments/'.$comment->id)->assertOk();

        $comment = Comment::create([
            'event_id' => $event->id,
            'user_id' => $author->id,
            'body' => 'Host deletes me',
        ]);

        Sanctum::actingAs($host);
        $this->deleteJson('/api/comments/'.$comment->id)->assertOk();

        $comment = Comment::create([
            'event_id' => $event->id,
            'user_id' => $author->id,
            'body' => 'Protected',
        ]);

        Sanctum::actingAs(User::factory()->create());
        $this->deleteJson('/api/comments/'.$comment->id)->assertForbidden();
    }

    public function test_users_can_follow_and_unfollow_each_other(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $host = User::factory()->create(['username' => 'dj_khaled']);
        Sanctum::actingAs($user);

        $this->postJson('/api/users/dj_khaled/follow')
            ->assertOk()
            ->assertJsonPath('following', true)
            ->assertJsonPath('followers_count', 1);

        Notification::assertSentTo($host, AppNotification::class);

        $this->postJson('/api/users/dj_khaled/follow')
            ->assertOk()
            ->assertJsonPath('following', false);

        $this->postJson('/api/users/'.$user->username.'/follow')
            ->assertUnprocessable();
    }

    public function test_profile_can_be_updated(): void
    {
        $user = User::factory()->create(['username' => 'old_name']);
        Sanctum::actingAs($user);

        $this->patchJson('/api/profile', [
            'name' => 'New Name',
            'username' => 'new_name',
            'bio' => 'Updated bio',
        ])->assertOk()
            ->assertJsonPath('data.username', 'new_name')
            ->assertJsonPath('data.name', 'New Name');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'username' => 'new_name']);
    }
}
