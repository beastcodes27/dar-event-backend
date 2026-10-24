<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'account_type' => ['required', 'string', Rule::in(['gov', 'ngo'])],
            'document' => ['required', 'file', 'mimes:pdf,doc,docx,png,jpg,jpeg', 'max:10240'],
            'paid' => ['sometimes', 'boolean'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
