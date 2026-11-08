<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Support\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FollowController extends Controller
{
    public function toggle(Request $request, User $user): JsonResponse
    {
        $auth = $request->user();

        abort_if($auth->is($user), 422, 'You cannot follow yourself.');
        abort_if($auth->hasBlockWith($user), 403, 'This account is unavailable.');

        $following = $auth->following()->where('users.id', $user->getKey())->exists();

        if ($following) {
            $auth->following()->detach($user->getKey());
        } else {
            $auth->following()->attach($user->getKey());

            $user->notify(new AppNotification('follow', [
                'message' => "{$auth->username} started following you.",
                'actor' => [
                    'id' => $auth->id,
                    'name' => $auth->name,
                    'username' => $auth->username,
                    'avatar' => Media::url($auth->avatar_path),
                ],
            ]));
        }

        return response()->json([
            'message' => $following ? 'Unfollowed successfully.' : 'Followed successfully.',
            'following' => ! $following,
            'followers_count' => $user->followers()->count(),
        ]);
    }
}
