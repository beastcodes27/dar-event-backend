<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVerificationRequest;
use App\Http\Resources\VerificationRequestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VerificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return VerificationRequestResource::collection(
            $request->user()->verificationRequests()->latest()->get()
        );
    }

    public function store(StoreVerificationRequest $request): JsonResponse
    {
        $user = $request->user();

        abort_if(
            $user->verificationRequests()->where('status', 'pending')->exists(),
            422,
            'You already have a verification request under review.'
        );

        $path = $request->file('document')->store('verifications', 'public');

        $verification = $user->verificationRequests()->create([
            'account_type' => $request->validated('account_type'),
            'document_path' => $path,
            'status' => 'pending',
            'payment_status' => $request->boolean('paid') ? 'paid' : 'unpaid',
            'payment_reference' => $request->validated('payment_reference'),
            'amount' => 9.99,
        ]);

        return response()->json([
            'message' => 'Verification request submitted for review.',
            'data' => new VerificationRequestResource($verification),
        ], 201);
    }
}
