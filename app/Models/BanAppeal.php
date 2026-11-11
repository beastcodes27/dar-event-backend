<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'ban_version', 'ban_reason', 'message', 'status', 'reviewed_by', 'reviewed_at', 'decision_reason'])]
class BanAppeal extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'ban_version' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
