<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class Textify
{
    public function configured(): bool
    {
        return (bool) config('services.textify.key') && str_starts_with(config('services.textify.url'), 'https://');
    }

    public function sendRecoveryCode(string $phone, string $code): void
    {
        if (! $this->configured()) {
            throw new RuntimeException('SMS recovery is not configured.');
        }
        $payload = ['is_scheduled' => false, 'channel' => 'sms', 'messages' => [['receiver' => ltrim($phone, '+'), 'content' => 'Your Veylo password reset code is '.$code.'. It expires in 10 minutes. Do not share this code.']]];
        if (config('services.textify.sender_name')) {
            $payload['sender_name'] = config('services.textify.sender_name');
        }
        $response = Http::baseUrl(rtrim(config('services.textify.url'), '/').'/')->withToken(config('services.textify.key'))->acceptJson()->asJson()->connectTimeout(3)->timeout(8)->post('messages', $payload);
        if (! $response->successful() || $response->json('success') !== true) {
            throw new RuntimeException('The SMS provider did not accept the recovery message.');
        }
    }
}
