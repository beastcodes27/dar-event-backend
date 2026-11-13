<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EnsureVeyloAccount extends Command
{
    protected $signature = 'app:ensure-veylo-account';

    protected $description = 'Create the official Veylo profile for onboarding suggestions without granting administrator access';

    public function handle(): int
    {
        $user = DB::transaction(function (): ?User {
            $existing = User::whereRaw('LOWER(username) = ?', ['veylo'])->lockForUpdate()->first();
            if ($existing) {
                return $existing->is_official ? $existing : null;
            }
            $user = User::create(['username' => 'veylo', 'name' => 'Veylo', 'email' => null, 'password' => Str::random(64), 'category' => 'Personal', 'bio' => 'Find your next experience. The official Veylo account.']);
            $user->forceFill(['is_official' => true, 'verified' => true, 'verified_at' => now(), 'onboarding_step' => 8, 'onboarding_completed_at' => now()])->save();

            return $user;
        });
        if (! $user) {
            $this->error('The username veylo belongs to an existing account. Review its ownership before configuring the official profile.');

            return self::FAILURE;
        }
        $this->info('Official Veylo profile is ready. No administrator access was granted.');

        return self::SUCCESS;
    }
}
