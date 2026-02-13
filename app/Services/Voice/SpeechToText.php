<?php

namespace App\Services\Voice;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SpeechToText
{
    protected ?string $apiKey;

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
            $content = file_get_contents($audio->getRealPath());
            $fileSize = strlen($content);

            // Check minimum file size (very short recordings fail)
            if ($fileSize < 1000) {
                return TranscriptionResult::failure('Recording too short. Please speak for at least 1 second.');
            }

            // Use the original filename extension if available, otherwise detect from mime type
            $originalExtension = pathinfo($audio->getClientOriginalName(), PATHINFO_EXTENSION);
            $mimeType = $audio->getMimeType() ?? $audio->getClientMimeType() ?? 'audio/webm';

            // Map mime types to extensions that OpenAI accepts
            $extension = $originalExtension ?: match (true) {
                str_contains($mimeType, 'mp4') => 'mp4',
                str_contains($mimeType, 'm4a') => 'm4a',
                str_contains($mimeType, 'mp3'), str_contains($mimeType, 'mpeg') => 'mp3',
                str_contains($mimeType, 'wav') => 'wav',
                str_contains($mimeType, 'ogg') => 'ogg',
                str_contains($mimeType, 'webm') => 'webm',
                default => 'webm',
            };

            $filename = 'audio.'.$extension;

            Log::debug('Whisper transcription request', [
                'original_name' => $audio->getClientOriginalName(),
                'mime_type' => $mimeType,
                'extension' => $extension,
                'file_size' => $fileSize,
            ]);

            $response = Http::withToken($this->apiKey)
                ->timeout(60)
                ->attach(
                    'file',
                    $content,
                    $filename
                )
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model' => $this->model,
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
                    'file_size' => $fileSize,
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
