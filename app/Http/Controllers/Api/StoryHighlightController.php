<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStoryHighlightRequest;
use App\Http\Resources\StoryHighlightResource;
use App\Models\StoryHighlight;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

class StoryHighlightController extends Controller
{
    public function index(Request $request, ?User $user = null): AnonymousResourceCollection
    {
        $user ??= $request->filled('username')
            ? User::where('username', $request->input('username'))->firstOrFail()
            : $request->user();

        $highlights = $user->storyHighlights()
            ->with(['stories' => fn ($query) => $query->active()->latest()])
            ->withCount('stories')
            ->orderBy('position')
            ->get();

        return StoryHighlightResource::collection($highlights);
    }

    public function store(StoreStoryHighlightRequest $request): JsonResponse
    {
        $data = $request->safe()->except('cover');
        $data['user_id'] = $request->user()->getKey();
        $data['position'] = $data['position'] ?? 0;

        if ($request->hasFile('cover')) {
            $data['cover_path'] = $request->file('cover')->store('highlights', 'public');
        }

        $highlight = StoryHighlight::create($data);

        return response()->json([
            'message' => 'Highlight created successfully.',
            'data' => new StoryHighlightResource($highlight->loadCount('stories')),
        ], 201);
    }

    public function destroy(Request $request, StoryHighlight $storyHighlight): JsonResponse
    {
        abort_unless($storyHighlight->user_id === $request->user()->getKey(), 404);

        if ($storyHighlight->cover_path) {
            Storage::disk('public')->delete($storyHighlight->cover_path);
        }

        $storyHighlight->delete();

        return response()->json(['message' => 'Highlight deleted successfully.']);
    }
}
