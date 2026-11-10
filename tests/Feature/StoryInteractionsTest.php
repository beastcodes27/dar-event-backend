<?php

namespace Tests\Feature;

use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StoryInteractionsTest extends TestCase
{
    use RefreshDatabase;

    private function story(User $owner): Story
    {
        return Story::create(['user_id' => $owner->id, 'caption' => 'A story', 'expires_at' => now()->addDay()]);
    }

    public function test_story_interactions_require_authentication(): void
    {
        $this->freezeTime();
        $story = $this->story(User::factory()->create());

        $this->postJson('/api/stories/'.$story->id.'/view')->assertUnauthorized();
        $this->postJson('/api/stories/'.$story->id.'/like', ['liked' => true])->assertUnauthorized();
        $this->getJson('/api/stories/'.$story->id.'/views')->assertUnauthorized();
        $this->assertDatabaseCount('story_views', 0);
        $this->assertDatabaseCount('story_likes', 0);
    }

    public function test_views_are_unique_persisted_and_visible_to_the_owner_only(): void
    {
        $this->freezeTime();
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $story = $this->story($owner);
        Sanctum::actingAs($viewer);

        $this->postJson('/api/stories/'.$story->id.'/view')->assertOk()->assertJsonPath('viewed', true);
        $this->postJson('/api/stories/'.$story->id.'/view')->assertOk();
        $this->assertDatabaseCount('story_views', 1);
        $this->assertDatabaseHas('story_views', ['story_id' => $story->id, 'user_id' => $viewer->id]);
        $this->getJson('/api/stories')->assertOk()->assertJsonPath('data.0.viewed', true)->assertJsonMissingPath('data.0.views_count');
        $this->getJson('/api/stories/'.$story->id.'/views')->assertNotFound();

        Sanctum::actingAs($owner);
        $this->postJson('/api/stories/'.$story->id.'/view')->assertOk();
        $this->assertDatabaseCount('story_views', 1);
        $this->getJson('/api/stories')->assertJsonPath('data.0.views_count', 1);
        $this->getJson('/api/stories/'.$story->id.'/views')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.user.id', $viewer->id)->assertJsonMissingPath('data.0.user.email');
    }

    public function test_liking_is_idempotent_and_unliking_updates_the_count(): void
    {
        $this->freezeTime();
        $viewer = User::factory()->create();
        $story = $this->story(User::factory()->create());
        Sanctum::actingAs($viewer);

        $this->postJson('/api/stories/'.$story->id.'/like', ['liked' => true])->assertOk()->assertJsonPath('liked', true)->assertJsonPath('likes_count', 1);
        $this->postJson('/api/stories/'.$story->id.'/like', ['liked' => true])->assertJsonPath('likes_count', 1);
        $this->assertDatabaseHas('story_likes', ['story_id' => $story->id, 'user_id' => $viewer->id]);
        $this->assertDatabaseCount('story_likes', 1);
        $this->getJson('/api/stories')->assertJsonPath('data.0.liked', true)->assertJsonPath('data.0.likes_count', 1);

        $this->postJson('/api/stories/'.$story->id.'/like', ['liked' => false])->assertOk()->assertJsonPath('liked', false)->assertJsonPath('likes_count', 0);
        $this->assertDatabaseCount('story_likes', 0);
    }

    public function test_like_requires_a_boolean_desired_state(): void
    {
        $this->freezeTime();
        $story = $this->story(User::factory()->create());
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/stories/'.$story->id.'/like', [])->assertUnprocessable()->assertJsonValidationErrors('liked');
        $this->postJson('/api/stories/'.$story->id.'/like', ['liked' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('liked');
        $this->assertDatabaseCount('story_likes', 0);
    }

    public function test_expired_missing_and_blocked_stories_reject_views_and_likes(): void
    {
        $this->freezeTime();
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $expired = $this->story(User::factory()->create());
        $expired->update(['expires_at' => now()->subSecond()]);
        $blocked = $this->story($owner);
        DB::table('account_blocks')->insert(['user_id' => $owner->id, 'blocked_user_id' => $viewer->id, 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($viewer);

        foreach ([$expired->id, $blocked->id, 999999] as $id) {
            $this->postJson('/api/stories/'.$id.'/view')->assertNotFound();
            $this->postJson('/api/stories/'.$id.'/like', ['liked' => true])->assertNotFound();
        }
        $this->postJson('/api/stories/'.$blocked->id.'/like', [])->assertNotFound();
        $this->getJson('/api/stories')->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('story_views', 0);
        $this->assertDatabaseCount('story_likes', 0);
    }

    public function test_publisher_viewer_lists_are_paginated_and_exclude_blocked_accounts(): void
    {
        $this->freezeTime();
        $owner = User::factory()->create();
        $story = $this->story($owner);
        $viewers = User::factory()->count(32)->create();
        foreach ($viewers as $viewer) {
            DB::table('story_views')->insert(['story_id' => $story->id, 'user_id' => $viewer->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('account_blocks')->insert(['user_id' => $owner->id, 'blocked_user_id' => $viewers->first()->id, 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($owner);

        $this->getJson('/api/stories/'.$story->id.'/views')->assertOk()->assertJsonCount(30, 'data')->assertJsonPath('meta.total', 31)
            ->assertJsonPath('data.0.user.id', $viewers[1]->id);
        $this->getJson('/api/stories/'.$story->id.'/views?page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('links.next', null);
    }

    public function test_story_replies_through_messenger_respect_requests_followers_and_blocking(): void
    {
        $this->freezeTime();
        $recipient = User::factory()->create(['message_policy' => 'requests']);
        $sender = User::factory()->create();
        Sanctum::actingAs($sender);
        $path = '/api/users/'.$recipient->username.'/messages';

        $this->postJson($path, ['body' => 'Replied to your story: A story\n\nNice!'])->assertCreated();
        $this->assertDatabaseHas('message_requests', ['sender_id' => $sender->id, 'recipient_id' => $recipient->id, 'status' => 'pending']);
        $this->postJson($path, ['body' => 'Another reply'])->assertForbidden();

        $followersOnly = User::factory()->create(['message_policy' => 'followers']);
        $this->postJson('/api/users/'.$followersOnly->username.'/messages', ['body' => 'Reply'])->assertForbidden();
        DB::table('account_blocks')->insert(['user_id' => $followersOnly->id, 'blocked_user_id' => $sender->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/users/'.$followersOnly->username.'/messages', ['body' => 'Reply'])->assertForbidden();
        $this->assertDatabaseCount('messages', 1);
    }
}
