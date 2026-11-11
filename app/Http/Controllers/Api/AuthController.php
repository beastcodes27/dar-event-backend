<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Rules\AvailableRegistrationIdentifier;
use App\Support\AccountBans;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'field' => ['required', 'in:username,email'],
            'value' => ['required', 'string', 'max:255'],
        ]);
        $field = $data['field'];
        $value = Str::lower(trim($data['value']));
        validator([$field => $value], [$field => RegisterRequest::identifierRules($field)])->validate();

        return response()->json(['data' => [
            'field' => $field,
            'value' => $value,
            'available' => ! AvailableRegistrationIdentifier::isTaken($field, $value),
        ]])->header('Cache-Control', 'no-store');
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request): User {
            $user = User::create($request->safe()->except(['terms', 'device_name', 'terms_version', 'privacy_policy_version']));
            $user->forceFill([
                'terms_accepted_at' => now(),
                'terms_version' => $request->validated('terms_version'),
                'privacy_policy_version' => $request->validated('privacy_policy_version'),
            ])->save();

            return $user;
        });

        $token = $user->createToken($request->input('device_name', 'mobile'))->plainTextToken;

        return response()->json([
            'message' => 'Account created successfully.',
            'token' => $token,
            'token_type' => 'Bearer',
            'data' => new UserResource(
                $user->loadCount(['followers', 'following', 'events'])
            ),
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $identifier = Str::lower(trim($request->input('login', $request->input('email'))));

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$identifier])
            ->orWhereRaw('LOWER(username) = ?', [$identifier])
            ->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'login' => ['The provided credentials are incorrect.'],
            ]);
        }

        $result = DB::transaction(function () use ($user, $request): array {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($locked->isBanned()) {
                return ['message' => $locked->ban_status === 'permanently_banned' ? 'Your account is permanently banned.' : 'Your account is banned. You can request a review.', 'code' => 'account_banned', 'ban' => app(AccountBans::class)->details($locked)];
            }

            return ['token' => $locked->createToken($request->input('device_name', 'mobile'))->plainTextToken];
        });
        if (isset($result['ban'])) {
            return response()->json($result, 403)->header('Cache-Control', 'no-store');
        }
        $token = $result['token'];

        return response()->json([
            'message' => 'Logged in successfully.',
            'token' => $token,
            'token_type' => 'Bearer',
            'data' => new UserResource(
                $user->loadCount(['followers', 'following', 'events'])
            ),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->loadCount(['followers', 'following', 'events']);

        return response()->json(['data' => new UserResource($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $request->user()->update([
            'password' => $request->validated('password'),
        ]);

        $currentToken = $request->user()->currentAccessToken();

        if ($currentToken) {
            $request->user()->tokens()->whereKeyNot($currentToken->getKey())->delete();
        }

        return response()->json(['message' => 'Password updated successfully.']);
    }
}
