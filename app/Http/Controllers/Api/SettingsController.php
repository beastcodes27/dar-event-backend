<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Http\Resources\UserResource;
use App\Models\Event;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class SettingsController extends Controller
{
    public function theme(Request $request): JsonResponse
    {
        $data = $request->validate(['theme_preference' => ['required', 'in:light,dark,system']]);
        $request->user()->update($data);

        return response()->json(['data' => new UserResource($request->user())]);
    }

    public function privacy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'discoverable' => ['required', 'boolean'],
            'allow_messages' => ['required', 'boolean'],
            'online_status_visibility' => ['sometimes', 'required', 'in:everyone,following,nobody'],
            'message_policy' => ['sometimes', 'required', 'in:everyone,followers,requests'],
        ]);
        $request->user()->update($data);

        return response()->json(['data' => new UserResource($request->user())]);
    }

    public function archived(Request $request): AnonymousResourceCollection
    {
        return EventResource::collection($request->user()->events()->withoutGlobalScope('active')
            ->whereNotNull('archived_at')->with('host')->latest('archived_at')->paginate(20));
    }

    public function archive(Request $request, string $eventId): JsonResponse
    {
        $data = $request->validate(['archived' => ['required', 'boolean']]);
        $event = Event::withoutGlobalScope('active')->where('user_id', $request->user()->id)->findOrFail($eventId);
        abort_if(! $data['archived'] && ! $request->user()->verified, 403, 'Only verified accounts can restore published events.');
        $event->archived_at = $data['archived'] ? now() : null;
        $event->save();

        return response()->json(['message' => $data['archived'] ? 'Post archived.' : 'Post restored.']);
    }

    public function blocked(Request $request): AnonymousResourceCollection
    {
        return UserResource::collection(User::whereIn('id', DB::table('account_blocks')
            ->where('user_id', $request->user()->id)->select('blocked_user_id'))->orderBy('username')->paginate(20));
    }

    public function block(Request $request, User $user): JsonResponse
    {
        $auth = $request->user();
        abort_if($auth->is($user), 422, 'You cannot block yourself.');
        DB::transaction(function () use ($auth, $user): void {
            DB::table('account_blocks')->insertOrIgnore([
                'user_id' => $auth->id, 'blocked_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $auth->following()->detach($user->id);
            $user->following()->detach($auth->id);
        });

        return response()->json(['message' => 'Account blocked.']);
    }

    public function unblock(Request $request, User $user): JsonResponse
    {
        DB::table('account_blocks')->where('user_id', $request->user()->id)->where('blocked_user_id', $user->id)->delete();

        return response()->json(['message' => 'Account unblocked. Following is not restored automatically.']);
    }

    public function activity(Request $request): JsonResponse
    {
        $user = $request->user();
        $tokens = $user->tokens()->latest()->get(['id', 'name', 'created_at', 'last_used_at']);

        return response()->json(['data' => [
            'joined_at' => $user->created_at,
            'posts' => $user->events()->count(),
            'archived_posts' => $user->events()->withoutGlobalScope('active')->whereNotNull('archived_at')->count(),
            'likes' => $user->likedEvents()->count(),
            'comments' => $user->comments()->count(),
            'saved' => $user->savedEvents()->count(),
            'sessions' => $tokens->map(fn ($token) => [
                'id' => $token->id, 'name' => $token->name, 'created_at' => $token->created_at,
                'last_used_at' => $token->last_used_at, 'is_current' => $token->id === $user->currentAccessToken()?->id,
            ]),
        ]]);
    }

    public function revokeSessions(Request $request): JsonResponse
    {
        $request->user()->tokens()->whereKeyNot($request->user()->currentAccessToken()?->id)->delete();

        return response()->json(['message' => 'Other sessions signed out.']);
    }

    public function support(Request $request): JsonResponse
    {
        return response()->json(DB::table('support_requests')->where('user_id', $request->user()->id)->latest()->paginate(20));
    }

    public function contact(Request $request): JsonResponse
    {
        $data = $request->validate(['subject' => ['required', 'string', 'max:150'], 'body' => ['required', 'string', 'max:5000']]);
        $id = DB::table('support_requests')->insertGetId($data + ['user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Support request submitted.', 'data' => ['id' => $id]], 201);
    }

    public function adminSupport(Request $request): JsonResponse
    {
        abort_unless($request->user()->is_admin, 403);

        return response()->json(DB::table('support_requests')->join('users', 'users.id', '=', 'support_requests.user_id')
            ->select('support_requests.*', 'users.username')->latest('support_requests.created_at')->paginate(20));
    }

    public function reply(Request $request, int $supportId): JsonResponse
    {
        abort_unless($request->user()->is_admin, 403);
        abort_unless(DB::table('support_requests')->where('id', $supportId)->exists(), 404);
        $data = $request->validate(['status' => ['required', 'in:open,in_progress,resolved'], 'admin_reply' => ['required', 'string', 'max:5000']]);
        DB::table('support_requests')->where('id', $supportId)->update($data + ['updated_at' => now()]);

        return response()->json(['message' => 'Support request updated.']);
    }
}
