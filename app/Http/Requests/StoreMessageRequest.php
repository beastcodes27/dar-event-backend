<?php

namespace App\Http\Requests;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $recipient = $this->route('user');

        return in_array($recipient->messagePermissionFor($this->user()), ['allowed', 'request_required'], true);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $this->merge(['body' => trim($this->input('body'))]);
        }
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:2000'],
            'reply_to_id' => ['nullable', 'integer', Rule::exists('messages', 'id')->where(function (Builder $query): void {
                $senderId = $this->user()->id;
                $recipientId = $this->route('user')->id;
                $query->whereNull('deleted_for_everyone_at')->where(function (Builder $query) use ($senderId, $recipientId): void {
                    $query->where(function (Builder $query) use ($senderId, $recipientId): void {
                        $query->where('sender_id', $senderId)->where('recipient_id', $recipientId)->whereNull('sender_deleted_at');
                    })->orWhere(function (Builder $query) use ($senderId, $recipientId): void {
                        $query->where('sender_id', $recipientId)->where('recipient_id', $senderId)->whereNull('recipient_deleted_at');
                    });
                });
            })],
        ];
    }
}
