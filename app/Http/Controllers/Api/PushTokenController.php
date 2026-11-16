<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PushTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'provider' => ['required', Rule::in(['expo', 'fcm'])],
            'platform' => ['nullable', Rule::in(['ios', 'android', 'web'])],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $token = PushToken::query()->updateOrCreate(
            ['token' => $data['token']],
            [
                'user_id' => $request->user()->id,
                'provider' => $data['provider'],
                'platform' => $data['platform'] ?? null,
                'device_name' => $data['device_name'] ?? null,
                'last_used_at' => now(),
            ],
        );

        return response()->json([
            'message' => 'Push token registered.',
            'data' => ['id' => $token->id, 'provider' => $token->provider],
        ], $token->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:512']]);
        $request->user()->pushTokens()->where('token', $data['token'])->delete();

        return response()->json(['message' => 'Push token removed.']);
    }
}
