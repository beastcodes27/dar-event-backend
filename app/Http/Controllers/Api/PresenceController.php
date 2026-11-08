<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class PresenceController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean']]);
        $token = $request->user()->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken, 403);
        $token->forceFill(['last_active_at' => $data['active'] ? now() : null])->save();

        return response()->json(['message' => 'Presence updated.'])->header('Cache-Control', 'no-store');
    }
}
