<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminProfileTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->forceFill(['is_admin' => true])->save();

        return $user;
    }

    public function test_only_administrators_can_access_their_portal_profile(): void
    {
        $this->get('/admin/profile')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create(), 'web')->get('/admin/profile')->assertForbidden();
        $this->patch('/admin/profile', ['name' => 'Changed'])->assertForbidden();
        $this->patch('/admin/profile/password', [])->assertForbidden();
    }

    public function test_administrators_edit_only_their_own_profile_and_privileges_are_preserved(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create();
        $this->actingAs($admin, 'web')->get('/admin/profile')->assertSee('My profile')->assertSee($admin->email);
        $this->patch('/admin/profile', ['name' => 'Updated Administrator', 'bio' => 'Event moderation team', 'user_id' => $other->id, 'is_admin' => false, 'verified' => true])->assertRedirect();
        $this->assertSame('Updated Administrator', $admin->fresh()->name);
        $this->assertTrue($admin->fresh()->is_admin);
        $this->assertFalse($admin->fresh()->verified);
        $this->assertSame($other->name, $other->fresh()->name);
    }

    public function test_changing_email_requires_current_password_and_unique_email(): void
    {
        $password = Str::random(32);
        $admin = $this->admin(['password' => $password]);
        $other = User::factory()->create();
        $this->actingAs($admin, 'web');
        $this->patch('/admin/profile', ['email' => 'new-admin@example.com'])->assertSessionHasErrors('current_password');
        $this->assertSame($admin->email, $admin->fresh()->email);
        $this->patch('/admin/profile', ['email' => $other->email, 'current_password' => $password])->assertSessionHasErrors('email');
        $this->patch('/admin/profile', ['email' => 'new-admin@example.com', 'current_password' => $password])->assertRedirect();
        $this->assertSame('new-admin@example.com', $admin->fresh()->email);
    }

    public function test_username_cooldown_is_shared_with_the_mobile_app(): void
    {
        $admin = $this->admin(['username_changed_at' => now()]);
        $this->actingAs($admin, 'web')->patch('/admin/profile', ['username' => 'another_admin'])->assertSessionHasErrors('username');
        $this->assertSame($admin->username, $admin->fresh()->username);
    }

    public function test_password_changes_require_confirmation_revoke_other_sessions_and_keep_current_session(): void
    {
        $old = Str::random(32);
        $new = Str::random(32);
        $admin = $this->admin(['password' => $old]);
        $other = User::factory()->create();
        $admin->createToken('phone');
        $otherToken = $other->createToken('other-phone');
        $this->actingAs($admin, 'web');
        $this->patch('/admin/profile/password', ['current_password' => 'incorrect', 'password' => $new, 'password_confirmation' => $new])->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check($old, $admin->fresh()->password));
        $this->patch('/admin/profile/password', ['current_password' => $old, 'password' => $new, 'password_confirmation' => 'mismatch'])->assertSessionHasErrors('password');
        $this->patch('/admin/profile/password', ['current_password' => $old, 'password' => $new, 'password_confirmation' => $new])->assertRedirect()->assertSessionHas('account_auth_version', 1);
        $this->assertTrue(Hash::check($new, $admin->fresh()->password));
        $this->assertSame(0, $admin->tokens()->count());
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken->accessToken->id]);
        $this->get('/admin/profile')->assertOk();
        $this->assertAuthenticatedAs($admin, 'web');
        $this->withSession(['account_auth_version' => 0])->get('/admin/profile')->assertRedirect('/admin/login');
    }

    public function test_administrators_replace_their_photo_without_affecting_another_account(): void
    {
        Storage::fake('public');
        $admin = $this->admin(['avatar_path' => 'avatars/old.jpg']);
        Storage::disk('public')->put('avatars/old.jpg', 'old photo');
        $this->actingAs($admin, 'web')->post('/admin/profile/avatar', ['avatar' => UploadedFile::fake()->createWithContent('avatar.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/j7sAAAAASUVORK5CYII='))])->assertRedirect();
        Storage::disk('public')->assertMissing('avatars/old.jpg');
        Storage::disk('public')->assertExists($admin->fresh()->avatar_path);
        $this->post('/admin/profile/avatar', ['avatar' => UploadedFile::fake()->create('document.pdf', 1, 'application/pdf')])->assertSessionHasErrors('avatar');
    }
}
