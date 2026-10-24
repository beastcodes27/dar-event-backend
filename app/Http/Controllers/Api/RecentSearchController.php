<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRecentSearchRequest;
use App\Http\Resources\RecentSearchResource;
use App\Models\RecentSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RecentSearchController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $searches = $request->user()->recentSearches()
            ->latest('updated_at')
            ->limit(10)
            ->get();

        return RecentSearchResource::collection($searches);
    }

    public function store(StoreRecentSearchRequest $request): JsonResponse
    {
        $user = $request->user();

        $search = $user->recentSearches()->updateOrCreate(
            ['query' => $request->validated('query')],
            [
                'type' => $request->validated('type') ?: 'search',
                'subtitle' => $request->validated('subtitle'),
            ]
        );

        $staleIds = $user->recentSearches()
            ->latest('updated_at')
            ->skip(10)
            ->take(PHP_INT_MAX)
            ->pluck('id');

        if ($staleIds->isNotEmpty()) {
            $user->recentSearches()->whereIn('id', $staleIds)->delete();
        }

        return response()->json([
            'message' => 'Search saved.',
            'data' => new RecentSearchResource($search),
        ], 201);
    }

    public function destroy(Request $request, RecentSearch $recentSearch): JsonResponse
    {
        abort_unless($recentSearch->user_id === $request->user()->getKey(), 404);

        $recentSearch->delete();

        return response()->json(['message' => 'Search removed.']);
    }

    public function clear(Request $request): JsonResponse
    {
        $request->user()->recentSearches()->delete();

        return response()->json(['message' => 'Recent searches cleared.']);
    }
}
