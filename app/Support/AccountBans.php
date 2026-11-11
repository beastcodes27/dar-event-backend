<?php

namespace App\Support;

use App\Models\BanAppeal;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountBans
{
    public function revokeAccess(User $user): void
    {
        $user->tokens()->delete();
        $user->forceFill(['remember_token' => Str::random(60)])->save();
        DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
    }

    public function ban(User $target, User $administrator, string $reason): void
    {
        DB::transaction(function () use ($target, $administrator, $reason): void {
            $users = User::whereIn('id', [$target->id, $administrator->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $admin = $users[$administrator->id];
            $user = $users[$target->id];
            abort_unless($admin->is_admin && ! $admin->isBanned(), 403);
            if ($user->is($admin)) {
                throw ValidationException::withMessages(['reason' => 'You cannot ban your own administrator account.']);
            }
            abort_if($user->isBanned(), 409, 'This account is already banned.');
            $user->forceFill(['ban_status' => 'banned', 'ban_reason' => $reason, 'banned_at' => now(), 'banned_by' => $admin->id, 'ban_version' => $user->ban_version + 1])->save();
            $this->revokeAccess($user);
        });
    }

    public function details(User $user): array
    {
        $appeal = BanAppeal::where('user_id', $user->id)->where('ban_version', $user->ban_version)->first();
        $canRequest = $user->ban_status === 'banned' && ! $appeal;

        return [
            'status' => $user->ban_status, 'reason' => $user->ban_reason,
            'appeal_status' => $appeal?->status, 'review_note' => $appeal?->decision_reason,
            'can_request_review' => $canRequest,
            'review_token' => $canRequest ? Crypt::encryptString(json_encode(['user_id' => $user->id, 'ban_version' => $user->ban_version, 'expires_at' => now()->addMinutes(30)->timestamp], JSON_THROW_ON_ERROR)) : null,
        ];
    }

    public function submit(string $token, string $message): BanAppeal
    {
        try {
            $claims = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['review_token' => 'Your review link is invalid. Sign in again to request a review.']);
        }
        if (! is_array($claims) || ! isset($claims['user_id'], $claims['ban_version'], $claims['expires_at']) || $claims['expires_at'] <= now()->timestamp) {
            throw ValidationException::withMessages(['review_token' => 'Your review link expired. Sign in again to request a review.']);
        }

        return DB::transaction(function () use ($claims, $message): BanAppeal {
            $user = User::whereKey($claims['user_id'])->lockForUpdate()->firstOrFail();
            abort_unless($user->ban_status === 'banned' && $user->ban_version === $claims['ban_version'], 403, 'A review is not available for this account restriction.');

            return BanAppeal::firstOrCreate(['user_id' => $user->id, 'ban_version' => $user->ban_version], [
                'message' => $message, 'ban_reason' => $user->ban_reason, 'status' => 'pending',
            ]);
        });
    }

    public function review(BanAppeal $appeal, User $administrator, string $status, string $reason): void
    {
        DB::transaction(function () use ($appeal, $administrator, $status, $reason): void {
            $users = User::whereIn('id', [$appeal->user_id, $administrator->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $admin = $users[$administrator->id];
            $user = $users[$appeal->user_id];
            abort_unless($admin->is_admin && ! $admin->isBanned(), 403);
            $locked = BanAppeal::whereKey($appeal->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'pending' && $user->ban_status === 'banned' && $user->ban_version === $locked->ban_version, 409, 'This review has already been resolved.');
            $locked->update(['status' => $status, 'decision_reason' => $reason, 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);
            $user->forceFill($status === 'accepted' ? ['ban_status' => 'active', 'ban_reason' => null, 'banned_at' => null, 'banned_by' => null] : ['ban_status' => 'permanently_banned'])->save();
            $this->revokeAccess($user);
        });
    }
}
