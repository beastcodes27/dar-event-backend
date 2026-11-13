<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountRecovery;
use App\Models\User;
use App\Support\Media;
use App\Support\PasswordRecovery;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordRecoveryController extends Controller
{
    public function find(Request $request, PasswordRecovery $recovery): JsonResponse
    {
        $data = $request->validate(['identifier' => ['required', 'string', 'max:255']]);
        $identifier = Str::lower(trim($data['identifier']));
        $channel = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'sms';
        if ($channel === 'sms') {
            $identifier = PhoneNumber::normalize($identifier);
            if (! preg_match('/^\\+[1-9][0-9]{7,14}$/D', $identifier ?? '')) {
                throw ValidationException::withMessages(['identifier' => 'Enter your email address or international phone number, for example +255712345678.']);
            }
        }
        $user = $channel === 'email' ? User::whereRaw('LOWER(email) = ?', [$identifier])->first() : User::where('phone', $identifier)->first();
        abort_unless($user, 404, 'No matching account was found. Check your email or phone number.');
        AccountRecovery::where('expires_at', '<', now()->subDay())->delete();
        $token = Str::random(64);
        AccountRecovery::create(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'lookup_channel' => $channel, 'identifier_hash' => $recovery->fingerprint($channel, $identifier), 'password_fingerprint' => hash('sha256', $user->password), 'expires_at' => now()->addMinutes(15)]);
        $channels = [];
        foreach (['email', 'sms'] as $delivery) {
            if ($destination = $recovery->destination($user, $delivery)) {
                $channels[] = ['id' => $delivery, 'label' => $recovery->masked($destination, $delivery)];
            }
        }

        return response()->json(['data' => ['recovery_token' => $token, 'account' => ['name' => $user->discoverable ? $user->name : mb_substr($user->name, 0, 1).'***', 'username' => $user->discoverable ? $user->username : null, 'avatar' => $user->discoverable ? Media::url($user->avatar_path) : null, 'initials' => $user->initials()], 'channels' => $channels, 'preferred_channel' => $channel]])->header('Cache-Control', 'no-store');
    }

    public function send(Request $request, PasswordRecovery $recovery): JsonResponse
    {
        $data = $request->validate(['recovery_token' => ['required', 'string', 'size:64'], 'channel' => ['required', 'in:email,sms']]);

        return response()->json(['data' => $recovery->send($data['recovery_token'], $data['channel'])])->header('Cache-Control', 'no-store');
    }

    public function verify(Request $request, PasswordRecovery $recovery): JsonResponse
    {
        $data = $request->validate(['recovery_token' => ['required', 'string', 'size:64'], 'code' => ['required', 'string', 'regex:/^[0-9]{6}$/D']]);

        return response()->json(['data' => ['reset_token' => $recovery->verify($data['recovery_token'], $data['code']), 'expires_in' => 600]])->header('Cache-Control', 'no-store');
    }

    public function reset(Request $request, PasswordRecovery $recovery): JsonResponse
    {
        $data = $request->validate(['recovery_token' => ['required', 'string', 'size:64'], 'reset_token' => ['required', 'string', 'size:64'], 'password' => ['required', 'string', 'min:6', 'confirmed']]);
        $recovery->reset($data['recovery_token'], $data['reset_token'], $data['password']);

        return response()->json(['message' => 'Password updated. Sign in with your new password.'])->header('Cache-Control', 'no-store');
    }
}
