<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Http\Resources\UserResource;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:100'],
            'type' => ['nullable', 'in:all,events,users'],
            'category' => ['nullable', 'string', 'in:'.implode(',', Event::CATEGORIES)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $term = '%'.$request->input('q').'%';
        $limit = $request->integer('limit', 20);
        $type = $request->input('type', 'all');
        $auth = $request->user('sanctum');

        $events = collect();
        $users = collect();

        if (in_array($type, ['all', 'events'], true)) {
            $events = Event::query()
                ->with('host')
                ->where('is_public', true)
                ->where(function (Builder $query) use ($term) {
                    $query->where('title', 'like', $term)
                        ->orWhere('description', 'like', $term)
                        ->orWhere('location', 'like', $term)
                        ->orWhere('category', 'like', $term)
                        ->orWhereHas('host', fn (Builder $host) => $host
                            ->where('name', 'like', $term)
                            ->orWhere('username', 'like', $term));
                })
                ->when($request->filled('category'), fn (Builder $query) => $query->where('category', $request->input('category')))
                ->when($auth, fn (Builder $query) => $query
                    ->withExists(['likes as is_liked' => fn (Builder $query) => $query->where('users.id', $auth->getKey())])
                    ->withExists(['savedBy as is_saved' => fn (Builder $query) => $query->where('users.id', $auth->getKey())]))
                ->orderByDesc('likes_count')
                ->limit($limit)
                ->get();
        }

        if (in_array($type, ['all', 'users'], true)) {
            $users = User::query()
                ->withCount(['followers', 'following', 'events'])
                ->where(function (Builder $query) use ($term) {
                    $query->where('name', 'like', $term)
                        ->orWhere('username', 'like', $term)
                        ->orWhere('bio', 'like', $term)
                        ->orWhere('category', 'like', $term);
                })
                ->limit($limit)
                ->get();
        }

        return response()->json([
            'data' => [
                'events' => EventResource::collection($events)->resolve(),
                'users' => UserResource::collection($users)->resolve(),
            ],
            'meta' => [
                'query' => $request->input('q'),
                'type' => $type,
                'events_count' => $events->count(),
                'users_count' => $users->count(),
            ],
        ]);
    }
}
