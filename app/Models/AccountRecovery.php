<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Guarded([])]
#[Hidden(['token_hash', 'identifier_hash', 'password_fingerprint', 'destination_hash', 'code_hash', 'reset_token_hash'])]
class AccountRecovery extends Model
{
    protected function casts(): array
    {
        return ['attempts' => 'integer', 'send_count' => 'integer', 'sent_at' => 'datetime', 'expires_at' => 'datetime', 'verified_at' => 'datetime', 'consumed_at' => 'datetime'];
    }
}
