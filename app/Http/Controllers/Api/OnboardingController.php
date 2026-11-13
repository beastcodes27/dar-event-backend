<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Support\Media;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OnboardingController extends Controller
{
    private function candidates(User $user): Builder
    {
        return User::whereKeyNot($user->id)->where('ban_status', 'active')->where('discoverable', true)->whereNotIn('id', $user->blockedAccountIds());
    }

    public function suggestions(Request $request): JsonResponse
    {
        $query = $this->candidates($request->user());
        $minimum = min(2, (clone $query)->count());
        $users = $query->withCount(['followers', 'following', 'events'])->orderByDesc('is_official')->orderByDesc('verified')->orderByDesc('followers_count')->orderBy('id')->limit(40)->get();

        return response()->json(['data' => UserResource::collection($users), 'meta' => ['minimum_to_follow' => $minimum]]);
    }

    public function progress(Request $request): JsonResponse
    {
        $data = $request->validate(['step' => ['required', 'integer', 'between:5,7']]);
        $user = DB::transaction(function () use ($request, $data): User {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if ($user->onboarding_completed_at) {
                return $user;
            }
            abort_if($data['step'] > $user->onboarding_step + 1, 422, 'Complete the current setup step first.');
            $user->forceFill(['onboarding_step' => max($user->onboarding_step, $data['step'])])->save();

            return $user;
        });

        return response()->json(['data' => new UserResource($user)]);
    }

    public function complete(Request $request): JsonResponse
    {
        $data = $request->validate(['user_ids' => ['present', 'array', 'max:40'], 'user_ids.*' => ['required', 'integer', 'distinct']]);
        $user = DB::transaction(function () use ($request, $data): User {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if ($user->onboarding_completed_at) {
                return $user;
            }
            abort_unless($user->onboarding_step === 7, 422, 'Complete your profile and permission choices first.');
            $query = $this->candidates($user);
            $minimum = min(2, (clone $query)->count());
            $ids = (clone $query)->whereIn('id', $data['user_ids'])->pluck('id');
            if ($ids->count() !== count($data['user_ids']) || $ids->count() < $minimum) {
                throw ValidationException::withMessages(['user_ids' => 'Choose at least '.$minimum.' available accounts to follow.']);
            }
            $added = $user->following()->syncWithoutDetaching($ids->all())['attached'];
            $user->forceFill(['onboarding_step' => 8, 'onboarding_completed_at' => now()])->save();
            DB::afterCommit(function () use ($added, $user): void {
                foreach (User::whereIn('id', $added)->get() as $target) {
                    $target->notify(new AppNotification('follow', ['message' => $user->username.' started following you.', 'actor' => ['id' => $user->id, 'name' => $user->name, 'username' => $user->username, 'avatar' => Media::url($user->avatar_path)]]));
                }
            });

            return $user;
        });

        return response()->json(['data' => new UserResource($user->loadCount(['followers', 'following', 'events']))]);
    }
}
