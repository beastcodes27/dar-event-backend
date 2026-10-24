<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UsernameChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_change_is_allowed_and_second_change_is_rejected(): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = User::factory()->create(['username' => 'original']);
        Sanctum::actingAs($user);

        $this->patchJson('/api/profile', ['username' => 'new_name'])
            ->assertOk()
            ->assertJsonPath('data.username', 'new_name')
            ->assertJsonPath('data.username_change_available_at', now()->addDays(7)->toIso8601String());

        $this->assertTrue($user->fresh()->username_changed_at->equalTo(now()));
        $this->patchJson('/api/profile', ['username' => 'another_name', 'bio' => 'Should not save'])
            ->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'username' => 'new_name']);
        $this->assertNotSame('Should not save', $user->fresh()->bio);
    }

    public function test_change_is_allowed_at_exactly_seven_days_and_resets_the_wait(): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = User::factory()->create(['username' => 'original']);
        $user->username_changed_at = now()->subDays(7)->addSecond();
        $user->save();
        Sanctum::actingAs($user);

        $this->patchJson('/api/profile', ['username' => 'new_name'])
            ->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->travel(1)->seconds();
        $this->patchJson('/api/profile', ['username' => 'new_name'])->assertOk();
        $this->assertTrue($user->fresh()->username_changed_at->equalTo(now()));
    }

    public function test_unchanged_username_and_other_profile_fields_do_not_reset_the_wait(): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = User::factory()->create(['username' => 'original']);
        $changedAt = now()->subDay();
        $user->username_changed_at = $changedAt;
        $user->save();
        Sanctum::actingAs($user);

        $this->patchJson('/api/profile', ['username' => 'original', 'bio' => 'Updated bio'])->assertOk();
        $this->patchJson('/api/profile', ['name' => 'Updated Name'])->assertOk();
        $this->assertTrue($user->fresh()->username_changed_at->equalTo($changedAt));
        $this->assertSame('Updated bio', $user->fresh()->bio);
    }

    public function test_invalid_username_does_not_start_the_wait_and_timestamp_cannot_be_overridden(): void
    {
        $user = User::factory()->create(['username' => 'original']);
        User::factory()->create(['username' => 'taken']);
        Sanctum::actingAs($user);

        $this->patchJson('/api/profile', ['username' => 'taken'])->assertUnprocessable();
        $this->patchJson('/api/profile', ['username' => 'bad username'])->assertUnprocessable();
        $this->assertNull($user->fresh()->username_changed_at);
        $this->patchJson('/api/profile', ['username' => 'valid_name', 'username_changed_at' => '2000-01-01'])->assertOk();
        $this->assertTrue($user->fresh()->username_changed_at->isToday());
    }
}
