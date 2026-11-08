<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMessageRequest;
use App\Http\Requests\UpdateMessageRequest;
use App\Http\Resources\InboxResource;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\MessageRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MessageController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['folder' => ['sometimes', 'in:inbox,requests']]);
        $authId = $request->user()->getKey();
        $incomingRequests = MessageRequest::query()->where('recipient_id', $authId);
        $latest = Message::query()->visibleTo($request->user())->where(function (Builder $query) use ($authId): void {
            $query->where(function (Builder $query) use ($authId): void {
                $query->where('sender_id', $authId)->whereColumn('recipient_id', 'users.id');
            })->orWhere(function (Builder $query) use ($authId): void {
                $query->where('recipient_id', $authId)->whereColumn('sender_id', 'users.id');
            });
        })->latest('id')->limit(1);

        $users = User::query()->select('users.*')
            ->whereNotIn('users.id', $request->user()->blockedAccountIds())
            ->when($request->input('folder') === 'requests',
                fn (Builder $query) => $query->whereIn('users.id', (clone $incomingRequests)->where('status', 'pending')->select('sender_id')),
                fn (Builder $query) => $query->whereNotIn('users.id', (clone $incomingRequests)->whereIn('status', ['pending', 'declined'])->select('sender_id')))
            ->where(function (Builder $query) use ($authId, $request): void {
                $query->whereIn('id', Message::query()->visibleTo($request->user())->select('sender_id')->where('recipient_id', $authId))
                    ->orWhereIn('id', Message::query()->visibleTo($request->user())->select('recipient_id')->where('sender_id', $authId));
            })
            ->selectSub((clone $latest)->select('body'), 'last_message')
            ->selectSub((clone $latest)->select('created_at'), 'last_message_at')
            ->selectSub((clone $latest)->select('id'), 'last_message_id')
            ->selectSub((clone $latest)->select('sender_id'), 'last_message_sender_id')
            ->selectSub((clone $latest)->select('read_at'), 'last_message_read_at')
            ->selectSub((clone $latest)->select('deleted_for_everyone_at'), 'last_message_deleted_at')
            ->selectSub((clone $incomingRequests)->whereColumn('sender_id', 'users.id')->select('id')->limit(1), 'request_id')
            ->selectSub((clone $incomingRequests)->whereColumn('sender_id', 'users.id')->select('status')->limit(1), 'request_status')
            ->withCount(['sentMessages as unread_count' => fn (Builder $query) => $query
                ->where('recipient_id', $authId)->whereNull('recipient_deleted_at')->whereNull('deleted_for_everyone_at')->whereNull('read_at')])
            ->orderByDesc('last_message_id')->paginate(20);

        return InboxResource::collection($users);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $requests = MessageRequest::where('recipient_id', $request->user()->getKey())
            ->whereNotIn('sender_id', $request->user()->blockedAccountIds());

        return response()->json(['count' => Message::query()
            ->whereNotIn('sender_id', $request->user()->blockedAccountIds())
            ->whereNotIn('sender_id', (clone $requests)->whereIn('status', ['pending', 'declined'])->select('sender_id'))
            ->where('recipient_id', $request->user()->getKey())->whereNull('recipient_deleted_at')->whereNull('deleted_for_everyone_at')->whereNull('read_at')->count(),
            'request_count' => (clone $requests)->where('status', 'pending')->whereIn('sender_id', Message::query()->visibleTo($request->user())->where('recipient_id', $request->user()->id)->select('sender_id'))->count(),
        ]);
    }

    public function show(Request $request, User $user): AnonymousResourceCollection
    {
        $request->validate(['after' => ['sometimes', 'date']]);
        $syncCursor = now()->startOfSecond();
        abort_if($request->user()->hasBlockWith($user), 403, 'This account is unavailable.');
        $authId = $request->user()->getKey();
        $incoming = MessageRequest::where('sender_id', $user->id)->where('recipient_id', $authId)->first();
        $thread = Message::query()->with('replyTo')->where(function (Builder $query) use ($authId, $user): void {
            $query->where(function (Builder $query) use ($authId, $user): void {
                $query->where('sender_id', $authId)->where('recipient_id', $user->getKey());
            })->orWhere(function (Builder $query) use ($authId, $user): void {
                $query->where('sender_id', $user->getKey())->where('recipient_id', $authId);
            });
        });
        $messages = (clone $thread)->visibleTo($request->user())->latest('id')->paginate(30);

        if (! $incoming || $incoming->status === 'accepted') {
            $readAt = now();
            Message::query()->whereIn('id', $messages->pluck('id'))
                ->where('recipient_id', $authId)->whereNull('read_at')->update(['read_at' => $readAt]);
            foreach ($messages as $message) {
                if ($message->recipient_id === $authId && ! $message->read_at) {
                    $message->read_at = $readAt;
                }
            }
        }

        return MessageResource::collection($messages)->additional([
            'meta' => [
                'is_online' => $user->onlineStatusFor($request->user()),
                'message_permission' => $user->messagePermissionFor($request->user()),
                'incoming_request' => $incoming ? ['id' => $incoming->id, 'status' => $incoming->status] : null,
                'sync_cursor' => $syncCursor->toIso8601String(),
                'changes' => $request->filled('after') ? MessageResource::collection((clone $thread)->where('updated_at', '>=', Carbon::parse($request->input('after')))->get()) : [],
            ],
        ]);
    }

    public function store(StoreMessageRequest $request, User $user): JsonResponse
    {
        $message = DB::transaction(function () use ($request, $user): Message {
            $recipient = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_if(MessageRequest::where('sender_id', $user->id)->where('recipient_id', $request->user()->id)->where('status', 'pending')->exists(), 409, 'Accept the message request before replying.');
            abort_if(MessageRequest::where('sender_id', $user->id)->where('recipient_id', $request->user()->id)->where('status', 'declined')->exists(), 409, 'This message request is closed.');
            $permission = $recipient->messagePermissionFor($request->user());
            abort_unless(in_array($permission, ['allowed', 'request_required'], true), 403, 'Messaging is unavailable for this account.');
            if ($permission === 'request_required') {
                $pending = MessageRequest::firstOrCreate(['sender_id' => $request->user()->id, 'recipient_id' => $user->id], ['status' => 'pending']);
                abort_unless($pending->wasRecentlyCreated, 409, 'A message request already exists.');
            }

            if ($request->validated('reply_to_id')) {
                $parent = Message::query()->visibleTo($request->user())->whereKey($request->validated('reply_to_id'))->whereNull('deleted_for_everyone_at')->lockForUpdate()->first();
                abort_unless($parent, 422, 'The message you are replying to is no longer available.');
            }

            return Message::create([
                'sender_id' => $request->user()->getKey(),
                'recipient_id' => $user->getKey(),
                'body' => $request->validated('body'),
                'reply_to_id' => $request->validated('reply_to_id'),
            ]);
        });

        return response()->json(['data' => new MessageResource($message->load('replyTo')), 'meta' => ['message_permission' => $user->messagePermissionFor($request->user())]], 201);
    }

    public function resolveRequest(Request $request, MessageRequest $messageRequest): JsonResponse
    {
        abort_unless($messageRequest->recipient_id === $request->user()->id, 404);
        $data = $request->validate(['status' => ['required', 'in:accepted,declined']]);
        $sender = User::findOrFail($messageRequest->sender_id);
        abort_if($request->user()->hasBlockWith($sender), 403, 'This account is unavailable.');
        if ($messageRequest->status !== $data['status']) {
            $changed = MessageRequest::whereKey($messageRequest->id)->where('status', 'pending')->update(['status' => $data['status'], 'updated_at' => now()]);
            abort_unless($changed, 409, 'This request has already been resolved.');
        }

        return response()->json(['message' => $data['status'] === 'accepted' ? 'Message request accepted.' : 'Message request declined.']);
    }

    public function update(UpdateMessageRequest $request, Message $message): JsonResponse
    {
        $updated = DB::transaction(function () use ($request, $message): Message {
            $current = Message::query()->visibleTo($request->user())->whereKey($message->id)->lockForUpdate()->firstOrFail();
            abort_if($current->deleted_for_everyone_at, 409, 'Deleted messages cannot be edited.');
            abort_unless($current->canBeEditedBy($request->user()), 403, 'Messages can only be edited within 10 minutes of sending.');
            if ($current->body !== $request->validated('body')) {
                $current->forceFill(['body' => $request->validated('body'), 'edited_at' => now()])->save();
            }

            return $current;
        });

        return response()->json(['data' => new MessageResource($updated->load('replyTo'))]);
    }

    public function destroy(Request $request, Message $message): JsonResponse
    {
        $userId = $request->user()->id;
        abort_unless(in_array($userId, [$message->sender_id, $message->recipient_id], true), 403);
        $data = $request->validate(['scope' => ['required', 'in:me,everyone']]);
        if ($data['scope'] === 'everyone') {
            abort_unless($message->sender_id === $userId, 403, 'Only the sender can delete a message for everyone.');
            Message::whereKey($message->id)->whereNull('deleted_for_everyone_at')->update([
                'body' => '', 'edited_at' => null, 'deleted_for_everyone_at' => now(),
            ]);

            return response()->json(['data' => new MessageResource($message->fresh())]);
        }

        $column = $message->sender_id === $userId ? 'sender_deleted_at' : 'recipient_deleted_at';
        Message::whereKey($message->id)->whereNull($column)->update([$column => now()]);

        return response()->json(['message' => 'Message deleted for you.', 'deleted_id' => $message->id]);
    }
}
