<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function show(Request $request, User $user): UserResource
    {
        abort_if($request->user('sanctum')?->hasBlockWith($user), 404);

        return new UserResource(
            $user->loadCount(['followers', 'following', 'events'])
        );
    }

    public function events(Request $request, User $user): AnonymousResourceCollection
    {
        $auth = $request->user('sanctum');
        abort_if($auth?->hasBlockWith($user), 404);

        $events = $user->events()
            ->with('host')
            ->when(! $auth || $auth->isNot($user), fn ($query) => $query->where('is_public', true))
            ->latest('starts_at')
            ->paginate($request->integer('per_page', 15));

        return EventResource::collection($events);
    }

    public function followers(Request $request, User $user): AnonymousResourceCollection
    {
        abort_if($request->user('sanctum')?->hasBlockWith($user), 404);
        $followers = $user->followers()
            ->withCount(['followers', 'following', 'events'])
            ->paginate($request->integer('per_page', 15));

        return UserResource::collection($followers);
    }

    public function following(Request $request, User $user): AnonymousResourceCollection
    {
        abort_if($request->user('sanctum')?->hasBlockWith($user), 404);
        $following = $user->following()
            ->withCount(['followers', 'following', 'events'])
            ->paginate($request->integer('per_page', 15));

        return UserResource::collection($following);
    }
}
