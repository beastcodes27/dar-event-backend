<?php

namespace App\Support;

use App\Models\PushToken;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PushNotifier
{
    public function __construct(private FirebaseMessenger $firebase) {}

    /**
     * @param  array{title: string, body: string, data?: array<string, string>}  $message
     */
    public function send(User $user, array $message): void
    {
        foreach ($user->pushTokens()->get() as $token) {
            try {
                $keep = match ($token->provider) {
                    'fcm' => $this->firebase->send($token->token, $message),
                    'expo' => $this->sendExpo($token, $message),
                    default => false,
                };
            } catch (\Throwable $exception) {
                Log::warning('Push delivery failed.', ['provider' => $token->provider, 'error' => $exception->getMessage()]);

                continue;
            }

            if (! $keep) {
                $token->delete();
            }
        }
    }

    /**
     * @param  array{title: string, body: string, data?: array<string, string>}  $message
     */
    private function sendExpo(PushToken $pushToken, array $message): bool
    {
        if (! preg_match('/^Expo(nent)?PushToken\[[^\]]+\]$/', $pushToken->token)) {
            return false;
        }

        $request = Http::acceptJson()->timeout(10);
        $accessToken = config('services.expo.access_token');
        if (is_string($accessToken) && $accessToken !== '') {
            $request = $request->withToken($accessToken);
        }

        $response = $request->post('https://exp.host/--/api/v2/push/send', [
            'to' => $pushToken->token,
            'title' => $message['title'],
            'body' => $message['body'],
            'data' => $message['data'] ?? [],
            'sound' => 'default',
            'priority' => 'high',
            'channelId' => 'default',
        ]);

        if ($response->failed()) {
            Log::warning('Expo push request failed.', ['status' => $response->status()]);

            return true;
        }

        $ticket = $response->json('data.0') ?? $response->json('data');
        if (($ticket['status'] ?? null) === 'error') {
            $error = (string) ($ticket['details']['error'] ?? 'unknown');
            Log::warning('Expo push rejected.', ['error' => $error]);

            return $error !== 'DeviceNotRegistered';
        }

        return true;
    }
}
