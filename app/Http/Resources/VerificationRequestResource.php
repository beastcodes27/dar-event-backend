<?php

namespace App\Http\Resources;

use App\Models\VerificationRequest;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin VerificationRequest */
class VerificationRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_type' => $this->account_type,
            'document' => Media::url($this->document_path),
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'payment_reference' => $this->payment_reference,
            'amount' => (float) $this->amount,
            'notes' => $this->notes,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
