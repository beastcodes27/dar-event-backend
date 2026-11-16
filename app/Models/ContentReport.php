<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded([])]
class ContentReport extends Model
{
    use HasFactory;

    public const REASONS = ['spam' => 'Spam or scam', 'harassment' => 'Bullying or harassment', 'hate' => 'Hate speech', 'violence' => 'Violence or dangerous content', 'sexual' => 'Sexual content', 'misleading' => 'False or misleading information', 'other' => 'Something else'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class)->withoutGlobalScopes();
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }
}
