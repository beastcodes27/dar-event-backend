<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new UserResource(
                $request->user()->loadCount(['followers', 'following', 'events'])
            ),
        ]);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        DB::transaction(function () use ($request): void {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->getKey());
            $data = $request->validated();

            if (isset($data['username']) && $data['username'] !== $user->username) {
                $availableAt = $user->username_changed_at?->copy()->addDays(7);

                if ($availableAt?->isFuture()) {
                    throw ValidationException::withMessages([
                        'username' => ['You can change your username again on '.$availableAt->toDayDateTimeString().'.'],
                    ]);
                }

                $user->username_changed_at = now();
            }

            $user->fill($data)->save();
        });

        return response()->json([
            'message' => 'Profile updated successfully.',
            'data' => new UserResource(
                $request->user()->fresh()->loadCount(['followers', 'following', 'events'])
            ),
        ]);
    }

    public function avatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'max:5120'],
        ]);

        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $path = $request->file('avatar')->store('avatars', 'public');
        $user->update(['avatar_path' => $path]);

        return response()->json([
            'message' => 'Profile photo updated successfully.',
            'data' => new UserResource(
                $user->fresh()->loadCount(['followers', 'following', 'events'])
            ),
        ]);
    }

    public function destroyAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->update(['avatar_path' => null]);
        }

        return response()->json([
            'message' => 'Profile photo removed successfully.',
            'data' => new UserResource(
                $user->fresh()->loadCount(['followers', 'following', 'events'])
            ),
        ]);
    }
}
