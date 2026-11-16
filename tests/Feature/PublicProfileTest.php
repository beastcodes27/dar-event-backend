<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_profiles_show_identity_stats_and_only_public_events(): void
    {
        $user = User::factory()->create(['name' => 'Iman Ibraahim', 'username' => 'iman_ibraahim', 'bio' => 'Community event host', 'category' => 'Event Organizer']);
        Event::factory()->create(['user_id' => $user->id, 'title' => 'Open air concert', 'is_public' => true]);
        Event::factory()->create(['user_id' => $user->id, 'title' => 'Private dinner', 'is_public' => false]);

        $this->get('/u/iman_ibraahim')
            ->assertOk()
            ->assertSee('Iman Ibraahim')
            ->assertSee('@iman_ibraahim')
            ->assertSee('Community event host')
            ->assertSee('<title>Iman Ibraahim (@iman_ibraahim) · Veylo</title>', false)
            ->assertSee('Open air concert')
            ->assertDontSee('Private dinner')
            ->assertSee(route('account.login'));
    }

    public function test_missing_and_banned_profiles_are_not_published(): void
    {
        $this->get('/u/missing_person')->assertNotFound();

        $banned = User::factory()->create(['username' => 'suspended_host']);
        $banned->forceFill(['ban_status' => 'banned'])->save();

        $this->get('/u/suspended_host')->assertNotFound();
    }

    public function test_public_profiles_escape_profile_content(): void
    {
        User::factory()->create(['username' => 'escape_test', 'bio' => '<script>alert(1)</script>']);

        $this->get('/u/escape_test')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }
}
