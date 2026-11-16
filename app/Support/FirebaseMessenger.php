<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FirebaseMessenger
{
    /** @var array<string, mixed>|null */
    private ?array $credentials = null;

    private bool $loaded = false;

    /**
     * @param  array{title: string, body: string, data?: array<string, string>}  $message
     */
    public function send(string $token, array $message): bool
    {
        $credentials = $this->credentials();
        if ($credentials === null) {
            return true;
        }

        $response = Http::withToken($this->accessToken($credentials))
            ->acceptJson()
            ->timeout(10)
            ->post("https://fcm.googleapis.com/v1/projects/{$credentials['project_id']}/messages:send", [
                'message' => [
                    'token' => $token,
                    'notification' => ['title' => $message['title'], 'body' => $message['body']],
                    'data' => array_map('strval', $message['data'] ?? []),
                    'android' => ['priority' => 'high', 'notification' => ['channel_id' => 'default', 'sound' => 'default']],
                    'apns' => ['payload' => ['aps' => ['sound' => 'default', 'badge' => 1]]],
                ],
            ]);

        if ($response->successful()) {
            return true;
        }

        $status = (string) $response->json('error.status');
        Log::warning('Firebase push failed.', ['status' => $response->status(), 'error' => $status]);

        return ! in_array($status, ['NOT_FOUND', 'INVALID_ARGUMENT', 'UNREGISTERED', 'SENDER_ID_MISMATCH'], true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function credentials(): ?array
    {
        if ($this->loaded) {
            return $this->credentials;
        }
        $this->loaded = true;

        $path = config('services.firebase.credentials');
        if (! is_string($path) || $path === '') {
            return null;
        }
        if (! is_file($path)) {
            Log::warning('Firebase credentials file was not found.', ['path' => $path]);

            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ! isset($data['client_email'], $data['private_key'], $data['project_id'])) {
            Log::warning('Firebase credentials are incomplete.');

            return null;
        }

        return $this->credentials = $data;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function accessToken(array $credentials): string
    {
        $cacheKey = 'firebase-access-token:'.$credentials['project_id'];
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $now = time();
        $header = self::base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = self::base64url(json_encode([
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR));
        $unsigned = $header.'.'.$claims;
        $signature = '';
        if (! openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the Firebase access token.');
        }

        $response = Http::asForm()->timeout(10)->post($credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $unsigned.'.'.self::base64url($signature),
        ]);
        $response->throw();

        $token = (string) $response->json('access_token');
        $expiresIn = (int) $response->json('expires_in', 3600);
        Cache::put($cacheKey, $token, max(60, $expiresIn - 60));

        return $token;
    }

    private static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
