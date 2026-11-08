<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStoryRequest;
use App\Http\Resources\StoryResource;
use App\Models\Story;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

class StoryController extends Controller
{
    public function index(Request $request, ?User $user = null): AnonymousResourceCollection
    {
        $user ??= $request->filled('username')
            ? User::where('username', $request->input('username'))->firstOrFail()
            : null;

        $stories = Story::query()
            ->when($request->user('sanctum'), fn ($query) => $query->whereNotIn('user_id', $request->user('sanctum')->blockedAccountIds()))
            ->with('user')
            ->active()
            ->when($user, fn ($query) => $query->where('user_id', $user->getKey()))
            ->latest()
            ->get();

        return StoryResource::collection($stories);
    }

    public function store(StoreStoryRequest $request): JsonResponse
    {
        $data = $request->safe()->except('media');
        $data['user_id'] = $request->user()->getKey();
        $data['is_live'] = $request->boolean('is_live');
        $data['expires_at'] = $data['expires_at'] ?? now()->addDay();

        if ($request->hasFile('media')) {
            $data['media_path'] = $request->file('media')->store('stories', 'public');
        }

        $story = Story::create($data);

        return response()->json([
            'message' => 'Story published successfully.',
            'data' => new StoryResource($story->load('user')),
        ], 201);
    }

    public function destroy(Request $request, Story $story): JsonResponse
    {
        abort_unless($story->user_id === $request->user()->getKey(), 404);

        if ($story->media_path) {
            Storage::disk('public')->delete($story->media_path);
        }

        $story->delete();

        return response()->json(['message' => 'Story deleted successfully.']);
    }
}
