<?php

namespace App\Services\Voice;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SpeechToText
{
    protected string $apiKey;

    protected string $model;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key');
        $this->model = config('services.openai.whisper_model', 'whisper-1');
    }

    /**
     * Transcribe audio file to text using OpenAI Whisper.
     */
    public function transcribe(UploadedFile $audio): TranscriptionResult
    {
        if (! $this->apiKey) {
            return TranscriptionResult::failure('OpenAI API key not configured');
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(30)
                ->attach(
                    'file',
                    file_get_contents($audio->getRealPath()),
                    $audio->getClientOriginalName()
                )
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model' => $this->model,
                    'language' => 'en',
                    'response_format' => 'json',
                ]);

            if ($response->failed()) {
                Log::error('Whisper transcription failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return TranscriptionResult::failure('Transcription failed: '.$response->status());
            }

            $data = $response->json();

            return TranscriptionResult::success($data['text'] ?? '');
        } catch (\Throwable $e) {
            Log::error('Whisper transcription error', [
                'error' => $e->getMessage(),
            ]);

            return TranscriptionResult::failure($e->getMessage());
        }
    }
}
