<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class LegalController extends Controller
{
    public function index(): JsonResponse
    {
        $documents = collect(config('legal'))->map(fn (array $document) => collect($document)->except('sections')->all());

        return response()->json(['data' => $documents])->header('Cache-Control', 'no-store');
    }

    public function show(string $document): JsonResponse
    {
        abort_unless(in_array($document, ['terms', 'privacy'], true), 404);

        return response()->json(['data' => config('legal.'.$document)])->header('Cache-Control', 'no-store');
    }
}
