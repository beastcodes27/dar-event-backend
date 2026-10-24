<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEventRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Http\Resources\EventResource;
use App\Models\Event;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EventController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'in:'.implode(',', Event::CATEGORIES)],
            'host' => ['nullable', 'string', 'max:30'],
            'location' => ['nullable', 'string', 'max:150'],
            'upcoming' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:latest,popular,soon'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $auth = $request->user('sanctum');

        $events = Event::query()
            ->with('host')
            ->where(function (Builder $query) use ($auth) {
                $query->where('is_public', true);

                if ($auth) {
                    $query->orWhere('user_id', $auth->getKey());
                }
            })
            ->when($request->filled('q'), function (Builder $query) use ($request) {
                $term = '%'.$request->input('q').'%';

                $query->where(function (Builder $query) use ($term) {
                    $query->where('title', 'like', $term)
                        ->orWhere('description', 'like', $term)
                        ->orWhere('location', 'like', $term)
                        ->orWhereHas('host', fn (Builder $host) => $host
                            ->where('name', 'like', $term)
                            ->orWhere('username', 'like', $term));
                });
            })
            ->when($request->filled('category'), fn (Builder $query) => $query->where('category', $request->input('category')))
            ->when($request->filled('host'), fn (Builder $query) => $query->whereHas(
                'host', fn (Builder $host) => $host->where('username', $request->input('host'))
            ))
            ->when($request->filled('location'), fn (Builder $query) => $query->where('location', 'like', '%'.$request->input('location').'%'))
            ->when($request->boolean('upcoming'), fn (Builder $query) => $query->where('starts_at', '>=', now()))
            ->when($auth, fn (Builder $query) => $query
                ->withExists(['likes as is_liked' => fn (Builder $query) => $query->where('users.id', $auth->getKey())])
                ->withExists(['savedBy as is_saved' => fn (Builder $query) => $query->where('users.id', $auth->getKey())]))
            ->when($request->input('sort', 'soon') === 'popular', fn (Builder $query) => $query->orderByDesc('likes_count'))
            ->when($request->input('sort', 'soon') === 'latest', fn (Builder $query) => $query->latest())
            ->when($request->input('sort', 'soon') === 'soon', fn (Builder $query) => $query->orderBy('starts_at'))
            ->paginate($request->integer('per_page', 15));

        return EventResource::collection($events);
    }

    public function show(Request $request, Event $event): EventResource
    {
        $auth = $request->user('sanctum');

        abort_if(! $event->is_public && ! $event->isHostedBy($auth), 404);

        $event->load([
            'host',
            'rootComments.user',
            'rootComments.replies.user',
        ]);

        return new EventResource($event);
    }

    public function store(StoreEventRequest $request): JsonResponse
    {
        $data = $request->safe()->except(['image', 'image_url']);
        $data['user_id'] = $request->user()->getKey();
        $data['slug'] = $this->uniqueSlug($request->input('title'));
        $data['price'] = $data['price'] ?? 0;
        $data['currency'] = strtoupper($data['currency'] ?? 'USD');
        $data['has_video'] = $request->boolean('has_video') || filled($request->input('video_url'));

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('events', 'public');
        } elseif ($request->filled('image_url')) {
            $data['image_path'] = $request->input('image_url');
        }

        $event = Event::create($data);

        return response()->json([
            'message' => 'Event created successfully.',
            'data' => new EventResource($event->load('host')),
        ], 201);
    }

    public function update(UpdateEventRequest $request, Event $event): JsonResponse
    {
        $data = $request->safe()->except(['image', 'image_url']);

        if ($request->filled('title') && $request->input('title') !== $event->title) {
            $data['slug'] = $this->uniqueSlug($request->input('title'), $event->getKey());
        }

        if ($request->has('has_video') || $request->filled('video_url')) {
            $data['has_video'] = $request->boolean('has_video') || filled($request->input('video_url'));
        }

        if ($request->hasFile('image')) {
            $this->deleteLocalImage($event);
            $data['image_path'] = $request->file('image')->store('events', 'public');
        } elseif ($request->filled('image_url')) {
            $this->deleteLocalImage($event);
            $data['image_path'] = $request->input('image_url');
        }

        $event->update($data);

        return response()->json([
            'message' => 'Event updated successfully.',
            'data' => new EventResource($event->fresh()->load('host')),
        ]);
    }

    public function destroy(Event $event): JsonResponse
    {
        $this->authorize('delete', $event);

        $this->deleteLocalImage($event);
        $event->delete();

        return response()->json(['message' => 'Event deleted successfully.']);
    }

    public function myEvents(Request $request): AnonymousResourceCollection
    {
        $events = $request->user()->events()
            ->with('host')
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return EventResource::collection($events);
    }

    public function feed(Request $request): AnonymousResourceCollection
    {
        $auth = $request->user();
        $followingIds = $auth->following()->pluck('users.id');

        $events = Event::query()
            ->with('host')
            ->where(function (Builder $query) use ($auth, $followingIds) {
                $query->where('user_id', $auth->getKey())
                    ->orWhere(function (Builder $query) use ($followingIds) {
                        $query->where('is_public', true)
                            ->whereIn('user_id', $followingIds);
                    });
            })
            ->latest('starts_at')
            ->paginate($request->integer('per_page', 15));

        return EventResource::collection($events);
    }

    private function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'event';
        $slug = $base;
        $count = 2;

        while (Event::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = $base.'-'.$count++;
        }

        return $slug;
    }

    private function deleteLocalImage(Event $event): void
    {
        if ($event->image_path && ! str_starts_with($event->image_path, 'http')) {
            Storage::disk('public')->delete($event->image_path);
        }
    }
}
