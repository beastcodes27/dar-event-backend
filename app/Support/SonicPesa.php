<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class SonicPesa
{
    public function configured(): bool
    {
        return filled(config('services.sonicpesa.key')) && filled(config('services.sonicpesa.secret'));
    }

    public function createOrder(array $buyer): array
    {
        return $this->request('payment/create_order', $buyer);
    }

    public function orderStatus(string $orderId): array
    {
        return $this->request('payment/order_status', ['order_id' => $orderId]);
    }

    private function request(string $endpoint, array $body): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Online payments are not configured yet.');
        }
        $result = Http::acceptJson()->withoutRedirecting()->withHeaders([
            'X-API-KEY' => config('services.sonicpesa.key'),
            'X-API-SECRET' => config('services.sonicpesa.secret'),
        ])->connectTimeout(8)->timeout(25)->post(rtrim(config('services.sonicpesa.url'), '/').'/'.$endpoint, $body);
        if (! $result->successful() || $result->json('status') !== 'success' || ! is_array($result->json('data'))) {
            throw new RuntimeException('SonicPesa could not confirm this request. Check your payment before trying again.');
        }

        return $result->json('data');
    }
}
