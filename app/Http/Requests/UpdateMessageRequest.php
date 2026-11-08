<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $message = $this->route('message');

        return $message->canBeEditedBy($this->user()) && ! $this->user()->hasBlockWith($message->recipient);
    }

    protected function failedAuthorization(): void
    {
        $message = $this->route('message');
        if ($message->sender_id === $this->user()->id && $message->created_at?->lessThanOrEqualTo(now()->subMinutes($message::EDIT_WINDOW_MINUTES))) {
            throw new AuthorizationException('Messages can only be edited within 10 minutes of sending.');
        }
        parent::failedAuthorization();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:2000']];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $this->merge(['body' => trim($this->input('body'))]);
        }
    }
}
