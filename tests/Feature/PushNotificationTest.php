<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_push_tokens_are_registered_reassigned_and_removed(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/push-tokens', ['token' => 'ExponentPushToken[device]', 'provider' => 'expo', 'platform' => 'android', 'device_name' => 'Pixel'])
            ->assertCreated()->assertJsonPath('data.provider', 'expo');
        $this->postJson('/api/push-tokens', ['token' => 'ExponentPushToken[device]', 'provider' => 'expo', 'platform' => 'android'])
            ->assertOk();
        $this->assertDatabaseCount('push_tokens', 1);

        Sanctum::actingAs($other);
        $this->postJson('/api/push-tokens', ['token' => 'ExponentPushToken[device]', 'provider' => 'expo', 'platform' => 'android'])
            ->assertOk();
        $this->assertDatabaseHas('push_tokens', ['token' => 'ExponentPushToken[device]', 'user_id' => $other->id]);

        $this->deleteJson('/api/push-tokens', ['token' => 'ExponentPushToken[device]'])->assertOk();
        $this->assertDatabaseCount('push_tokens', 0);

        Sanctum::actingAs($user);
        $this->postJson('/api/push-tokens', ['token' => 'unknown', 'provider' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('provider');
    }

    public function test_likes_send_expo_push_notifications_to_the_author(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://exp.host/--/api/v2/push/send' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]])]);

        $host = User::factory()->create();
        $host->pushTokens()->create(['token' => 'ExponentPushToken[host]', 'provider' => 'expo', 'platform' => 'android']);
        $event = Event::factory()->create(['user_id' => $host->id]);
        $fan = User::factory()->create();
        Sanctum::actingAs($fan);

        $this->postJson('/api/events/'.$event->id.'/like', ['liked' => true])->assertOk();

        Http::assertSent(fn ($request) => $request->url() === 'https://exp.host/--/api/v2/push/send'
            && $request['to'] === 'ExponentPushToken[host]'
            && $request['title'] === 'New like on your post'
            && str_contains($request['body'], 'liked your event'));
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $host->id, 'notifiable_type' => User::class]);
    }

    public function test_messages_send_firebase_push_notifications_to_the_recipient(): void
    {
        $path = $this->firebaseCredentials();
        config(['services.firebase.credentials' => $path]);
        Cache::forget('firebase-access-token:veylo-test');
        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token', 'expires_in' => 3600]),
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'projects/veylo-test/messages/1']),
        ]);

        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $recipient->pushTokens()->create(['token' => 'fcm-device-token', 'provider' => 'fcm', 'platform' => 'android']);
        Sanctum::actingAs($sender);

        try {
            $this->postJson('/api/users/'.$recipient->username.'/messages', ['body' => 'Hello there'])->assertCreated();
        } finally {
            @unlink($path);
        }

        Http::assertSent(fn ($request) => $request->url() === 'https://fcm.googleapis.com/v1/projects/veylo-test/messages:send'
            && $request->hasHeader('Authorization', 'Bearer access-token')
            && $request['message']['token'] === 'fcm-device-token'
            && $request['message']['notification']['title'] === 'New message'
            && str_contains($request['message']['notification']['body'], 'sent you a message'));
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $recipient->id, 'notifiable_type' => User::class]);
    }

    public function test_new_logins_alert_the_account_owner(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://exp.host/--/api/v2/push/send' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]])]);

        $user = User::factory()->create(['password' => 'secret123', 'email' => 'login@example.com']);
        $user->pushTokens()->create(['token' => 'ExponentPushToken[login]', 'provider' => 'expo', 'platform' => 'ios']);

        $this->postJson('/api/auth/login', ['login' => $user->email, 'password' => 'secret123', 'device_name' => 'iPhone'])->assertOk();

        Http::assertSent(fn ($request) => $request->url() === 'https://exp.host/--/api/v2/push/send'
            && $request['title'] === 'New login detected'
            && str_contains($request['body'], 'iPhone'));
        $this->assertSame('login', $user->notifications()->first()->data['type']);
    }

    public function test_invalid_push_tokens_are_deleted_after_delivery_fails(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://exp.host/--/api/v2/push/send' => Http::response(['data' => [['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']]]])]);

        $user = User::factory()->create();
        $token = $user->pushTokens()->create(['token' => 'ExponentPushToken[stale]', 'provider' => 'expo', 'platform' => 'android']);

        $user->notify(new AppNotification('follow', ['message' => 'Someone followed you.']));

        $this->assertDatabaseMissing('push_tokens', ['id' => $token->id]);
    }

    private function firebaseCredentials(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $path = tempnam(sys_get_temp_dir(), 'veylo-fcm-');
        file_put_contents($path, json_encode([
            'type' => 'service_account',
            'project_id' => 'veylo-test',
            'client_email' => 'push@veylo-test.iam.gserviceaccount.com',
            'private_key' => $private,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));

        return $path;
    }
}
