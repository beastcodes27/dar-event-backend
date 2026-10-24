<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMessageRequest;
use App\Http\Resources\InboxResource;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MessageController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $authId = $request->user()->getKey();
        $latest = Message::query()->where(function (Builder $query) use ($authId): void {
            $query->where(function (Builder $query) use ($authId): void {
                $query->where('sender_id', $authId)->whereColumn('recipient_id', 'users.id');
            })->orWhere(function (Builder $query) use ($authId): void {
                $query->where('recipient_id', $authId)->whereColumn('sender_id', 'users.id');
            });
        })->latest('id')->limit(1);

        $users = User::query()->select('users.*')
            ->where(function (Builder $query) use ($authId): void {
                $query->whereIn('id', Message::query()->select('sender_id')->where('recipient_id', $authId))
                    ->orWhereIn('id', Message::query()->select('recipient_id')->where('sender_id', $authId));
            })
            ->selectSub((clone $latest)->select('body'), 'last_message')
            ->selectSub((clone $latest)->select('created_at'), 'last_message_at')
            ->selectSub((clone $latest)->select('id'), 'last_message_id')
            ->withCount(['sentMessages as unread_count' => fn (Builder $query) => $query
                ->where('recipient_id', $authId)->whereNull('read_at')])
            ->orderByDesc('last_message_id')->paginate(20);

        return InboxResource::collection($users);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['count' => Message::query()
            ->where('recipient_id', $request->user()->getKey())->whereNull('read_at')->count()]);
    }

    public function show(Request $request, User $user): AnonymousResourceCollection
    {
        $authId = $request->user()->getKey();
        $messages = Message::query()->where(function (Builder $query) use ($authId, $user): void {
            $query->where(function (Builder $query) use ($authId, $user): void {
                $query->where('sender_id', $authId)->where('recipient_id', $user->getKey());
            })->orWhere(function (Builder $query) use ($authId, $user): void {
                $query->where('sender_id', $user->getKey())->where('recipient_id', $authId);
            });
        })->latest('id')->paginate(30);

        Message::query()->whereIn('id', $messages->pluck('id'))
            ->where('recipient_id', $authId)->whereNull('read_at')->update(['read_at' => now()]);

        return MessageResource::collection($messages);
    }

    public function store(StoreMessageRequest $request, User $user): JsonResponse
    {
        $message = Message::create([
            'sender_id' => $request->user()->getKey(),
            'recipient_id' => $user->getKey(),
            'body' => $request->validated('body'),
        ]);

        return response()->json(['data' => new MessageResource($message)], 201);
    }
}
