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
            // Get the file content and determine proper extension
            $content = file_get_contents($audio->getRealPath());
            $mimeType = $audio->getMimeType() ?? 'audio/webm';

            // Map mime types to extensions that OpenAI accepts
            $extension = match ($mimeType) {
                'audio/webm' => 'webm',
                'audio/mp3', 'audio/mpeg' => 'mp3',
                'audio/wav', 'audio/x-wav' => 'wav',
                'audio/mp4', 'audio/m4a', 'audio/x-m4a' => 'm4a',
                'audio/ogg' => 'ogg',
                default => 'webm',
            };

            $filename = 'audio.'.$extension;

            $response = Http::withToken($this->apiKey)
                ->timeout(30)
                ->attach(
                    'file',
                    $content,
                    $filename,
                    ['Content-Type' => $mimeType]
                )
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model' => $this->model,
                    'language' => 'en',
                    'response_format' => 'json',
                ]);

            if ($response->failed()) {
                $errorBody = $response->json();
                $errorMessage = $errorBody['error']['message'] ?? $response->body();

                Log::error('Whisper transcription failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'mime_type' => $mimeType,
                    'filename' => $filename,
                    'file_size' => strlen($content),
                ]);

                return TranscriptionResult::failure('Transcription failed: '.$errorMessage);
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
