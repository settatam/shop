<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Voice\VoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoiceController extends Controller
{
    public function __construct(
        protected VoiceService $voiceService
    ) {}

    /**
     * Process a voice query from audio input.
     */
    public function query(Request $request): JsonResponse
    {
        $request->validate([
            'audio' => 'required|file|mimes:mp3,wav,webm,m4a,ogg|max:10240',
        ]);

        $user = $request->user();
        $storeId = $user->current_store_id;

        if (! $storeId) {
            return response()->json([
                'success' => false,
                'error' => 'No store selected',
            ], 400);
        }

        $response = $this->voiceService->processVoiceQuery(
            $request->file('audio'),
            $storeId
        );

        return response()->json($response->toArray());
    }

    /**
     * Process a text query (for testing/fallback).
     */
    public function textQuery(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'required|string|max:500',
        ]);

        $user = $request->user();
        $storeId = $user->current_store_id;

        if (! $storeId) {
            return response()->json([
                'success' => false,
                'error' => 'No store selected',
            ], 400);
        }

        $response = $this->voiceService->processTextQuery(
            $request->input('query'),
            $storeId
        );

        return response()->json($response->toArray());
    }
}
