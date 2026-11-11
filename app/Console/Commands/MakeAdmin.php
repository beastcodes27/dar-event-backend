<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakeAdmin extends Command
{
    protected $signature = 'app:make-admin {email : Email of an existing DarEvent account}';

    protected $description = 'Grant an existing account access to the DarEvent web administration portal';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('Create this account in DarEvent first.');

            return self::FAILURE;
        }
        $user->forceFill(['is_admin' => true])->save();
        $this->info('Administration access granted to '.$user->email.'.');

        return self::SUCCESS;
    }
}
