<?php

namespace App\Support;

use App\Models\AccountRecovery;
use App\Models\User;
use App\Notifications\PasswordRecoveryCode;
use App\Services\Textify;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordRecovery
{
    public function __construct(private Textify $textify) {}

    public function fingerprint(string $channel, string $value): string
    {
        return hash_hmac('sha256', $channel.':'.Str::lower($value), config('app.key'));
    }

    public function destination(User $user, string $channel): ?string
    {
        return $channel === 'email' ? $user->email : $user->phone;
    }

    public function masked(string $value, string $channel): string
    {
        if ($channel === 'email') {
            [$local, $domain] = explode('@', $value, 2);

            return substr($local, 0, 1).'***@'.$domain;
        }

        return substr($value, 0, 4).'******'.substr($value, -3);
    }

    public function context(string $token): AccountRecovery
    {
        $challenge = AccountRecovery::where('token_hash', hash('sha256', $token))->first();
        if (! $challenge || $challenge->consumed_at || $challenge->expires_at->isPast()) {
            throw ValidationException::withMessages(['recovery_token' => 'This recovery request expired. Search for your account again.']);
        }

        return $challenge;
    }

    private function matchesAccount(AccountRecovery $challenge, User $user): bool
    {
        $identifier = $this->destination($user, $challenge->lookup_channel);

        return $identifier && hash_equals($challenge->identifier_hash, $this->fingerprint($challenge->lookup_channel, $identifier)) && hash_equals($challenge->password_fingerprint, hash('sha256', $user->password));
    }

    public function send(string $token, string $channel): array
    {
        $context = $this->context($token);
        $code = (string) random_int(100000, 999999);
        [$challenge, $user, $destination] = DB::transaction(function () use ($context, $channel, $code): array {
            $user = User::whereKey($context->user_id)->lockForUpdate()->firstOrFail();
            $challenge = AccountRecovery::whereKey($context->id)->lockForUpdate()->firstOrFail();
            abort_if($challenge->consumed_at || $challenge->expires_at->isPast() || $challenge->verified_at || ! $this->matchesAccount($challenge, $user), 422, 'This recovery request is no longer valid. Search again.');
            $destination = $this->destination($user, $channel);
            if (! $destination) {
                throw ValidationException::withMessages(['channel' => 'This account does not have that recovery contact.']);
            }
            if ($channel === 'sms' && ! $this->textify->configured()) {
                abort(503, 'SMS recovery is not configured yet. Please try email or contact the administrator.');
            }
            if ($channel === 'email' && in_array(config('mail.default'), ['log', 'array'], true)) {
                abort(503, 'Email recovery is not configured yet. Please try SMS or contact the administrator.');
            }
            $rateKey = 'password-recovery-send:'.$user->id;
            abort_if(($challenge->sent_at && $challenge->sent_at->greaterThan(now()->subMinute())) || $challenge->send_count >= 3 || RateLimiter::tooManyAttempts($rateKey, 5), 429, 'Please wait before requesting another code.');
            RateLimiter::hit($rateKey, 3600);
            AccountRecovery::where('user_id', $user->id)->whereKeyNot($challenge->id)->whereNull('consumed_at')->update(['consumed_at' => now(), 'code_hash' => null, 'reset_token_hash' => null]);
            $challenge->forceFill(['delivery_channel' => $channel, 'destination_hash' => $this->fingerprint($channel, $destination), 'code_hash' => Hash::make($code), 'attempts' => 0, 'send_count' => $challenge->send_count + 1, 'sent_at' => now(), 'expires_at' => now()->addMinutes(10)])->save();

            return [$challenge, $user, $destination];
        });
        try {
            if ($channel === 'email') {
                $user->notify(new PasswordRecoveryCode($code));
            } else {
                $this->textify->sendRecoveryCode($destination, $code);
            }
        } catch (\Throwable) {
            AccountRecovery::whereKey($challenge->id)->update(['code_hash' => null, 'consumed_at' => now()]);
            abort(503, 'Could not send your code. Search for your account and try again later.');
        }

        return ['channel' => $channel, 'destination' => $this->masked($destination, $channel), 'expires_in' => 600, 'resend_after' => 60];
    }

    public function verify(string $token, string $code): string
    {
        $context = $this->context($token);
        $result = DB::transaction(function () use ($context, $code): ?string {
            $challenge = AccountRecovery::whereKey($context->id)->lockForUpdate()->firstOrFail();
            if ($challenge->consumed_at || $challenge->expires_at->isPast() || ! $challenge->code_hash || $challenge->verified_at || $challenge->attempts >= 5) {
                return null;
            }
            $challenge->attempts++;
            if (! Hash::check($code, $challenge->code_hash)) {
                if ($challenge->attempts >= 5) {
                    $challenge->consumed_at = now();
                    $challenge->code_hash = null;
                }
                $challenge->save();

                return null;
            }
            $resetToken = Str::random(64);
            $challenge->forceFill(['verified_at' => now(), 'code_hash' => null, 'reset_token_hash' => hash('sha256', $resetToken), 'expires_at' => now()->addMinutes(10)])->save();

            return $resetToken;
        });
        if (! $result) {
            throw ValidationException::withMessages(['code' => 'The code is incorrect or expired. Use the latest code, or request a new one.']);
        }

        return $result;
    }

    public function reset(string $token, string $resetToken, string $password): void
    {
        $context = $this->context($token);
        DB::transaction(function () use ($context, $resetToken, $password): void {
            $user = User::whereKey($context->user_id)->lockForUpdate()->firstOrFail();
            $challenge = AccountRecovery::whereKey($context->id)->lockForUpdate()->firstOrFail();
            $destination = $challenge->delivery_channel ? $this->destination($user, $challenge->delivery_channel) : null;
            if ($challenge->consumed_at || $challenge->expires_at->isPast() || ! $challenge->verified_at || ! $challenge->reset_token_hash || ! hash_equals($challenge->reset_token_hash, hash('sha256', $resetToken)) || ! $this->matchesAccount($challenge, $user) || ! $destination || ! hash_equals($challenge->destination_hash, $this->fingerprint($challenge->delivery_channel, $destination))) {
                throw ValidationException::withMessages(['reset_token' => 'This password reset expired. Search for your account again.']);
            }
            $user->forceFill(['password' => $password])->save();
            app(AccountBans::class)->revokeAccess($user);
            AccountRecovery::where('user_id', $user->id)->whereNull('consumed_at')->update(['consumed_at' => now(), 'code_hash' => null, 'reset_token_hash' => null]);
        });
    }
}
