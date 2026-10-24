<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->safe()->except(['terms', 'device_name']));

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
        $identifier = $request->input('login', $request->input('email'));

        $user = User::query()
            ->where('email', $identifier)
            ->orWhere('username', $identifier)
            ->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'login' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken($request->input('device_name', 'mobile'))->plainTextToken;

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
