<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStoryRequest;
use App\Http\Resources\StoryResource;
use App\Http\Resources\UserResource;
use App\Models\Story;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
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
            ->withInteractions($request->user('sanctum'))
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

    private function assertVisible(Request $request, Story $story): void
    {
        abort_if($request->user()->hasBlockWith($story->user), 404);
        abort_unless(Story::query()->whereKey($story->id)->active()->exists(), 404, 'This story has expired.');
    }

    public function view(Request $request, Story $story): JsonResponse
    {
        $this->assertVisible($request, $story);
        if ($request->user()->id !== $story->user_id) {
            DB::table('story_views')->insertOrIgnore([
                'story_id' => $story->id, 'user_id' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return response()->json(['viewed' => true]);
    }

    public function like(Request $request, Story $story): JsonResponse
    {
        $this->assertVisible($request, $story);
        $request->validate(['liked' => ['required', 'boolean']]);
        $liked = $request->boolean('liked');
        $count = DB::transaction(function () use ($request, $story, $liked): int {
            Story::query()->whereKey($story->id)->lockForUpdate()->firstOrFail();
            if ($liked) {
                DB::table('story_likes')->insertOrIgnore([
                    'story_id' => $story->id, 'user_id' => $request->user()->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                $story->likes()->detach($request->user()->id);
            }

            return $story->likes()->count();
        });

        return response()->json(['liked' => $liked, 'likes_count' => $count]);
    }

    public function viewers(Request $request, Story $story): JsonResponse
    {
        abort_unless($request->user()->id === $story->user_id, 404);
        $viewers = $story->viewers()->whereNotIn('users.id', $request->user()->blockedAccountIds())
            ->orderByPivot('created_at', 'desc')->orderBy('users.id')->paginate(30);

        return response()->json([
            'data' => $viewers->getCollection()->map(fn (User $user) => [
                'user' => new UserResource($user), 'viewed_at' => $user->pivot->created_at?->toIso8601String(),
            ]),
            'links' => ['next' => $viewers->nextPageUrl()],
            'meta' => ['total' => $viewers->total(), 'likes_count' => $story->likes()->count()],
        ]);
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
