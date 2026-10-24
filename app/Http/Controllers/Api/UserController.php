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
        return new UserResource(
            $user->loadCount(['followers', 'following', 'events'])
        );
    }

    public function events(Request $request, User $user): AnonymousResourceCollection
    {
        $auth = $request->user('sanctum');

        $events = $user->events()
            ->with('host')
            ->when($auth?->isNot($user), fn ($query) => $query->where('is_public', true))
            ->latest('starts_at')
            ->paginate($request->integer('per_page', 15));

        return EventResource::collection($events);
    }

    public function followers(Request $request, User $user): AnonymousResourceCollection
    {
        $followers = $user->followers()
            ->withCount(['followers', 'following', 'events'])
            ->paginate($request->integer('per_page', 15));

        return UserResource::collection($followers);
    }

    public function following(Request $request, User $user): AnonymousResourceCollection
    {
        $following = $user->following()
            ->withCount(['followers', 'following', 'events'])
            ->paginate($request->integer('per_page', 15));

        return UserResource::collection($following);
    }
}
