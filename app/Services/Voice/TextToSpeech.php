<?php

namespace App\Services\Voice;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TextToSpeech
{
    protected ?string $apiKey;

    protected string $model;

    protected string $voice;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key');
        $this->model = config('services.openai.tts_model', 'tts-1');
        $this->voice = config('services.openai.tts_voice', 'alloy');
    }

    /**
     * Convert text to speech using OpenAI TTS.
     */
    public function synthesize(string $text): SynthesisResult
    {
        if (! $this->apiKey) {
            Log::warning('TTS: OpenAI API key not configured');

            return SynthesisResult::failure('OpenAI API key not configured');
        }

        // Truncate text to avoid excessive TTS costs
        $text = Str::limit($text, 4000, '...');

        Log::debug('TTS: Starting synthesis', [
            'text_length' => strlen($text),
            'model' => $this->model,
            'voice' => $this->voice,
        ]);

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(30)
                ->post('https://api.openai.com/v1/audio/speech', [
                    'model' => $this->model,
                    'input' => $text,
                    'voice' => $this->voice,
                    'response_format' => 'mp3',
                ]);

            if ($response->failed()) {
                Log::error('TTS synthesis failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return SynthesisResult::failure('Speech synthesis failed: '.$response->status());
            }

            // Ensure directory exists
            $directory = 'voice/responses';
            if (! Storage::disk('public')->exists($directory)) {
                Storage::disk('public')->makeDirectory($directory);
            }

            // Save the audio file
            $filename = $directory.'/'.Str::uuid().'.mp3';
            Storage::disk('public')->put($filename, $response->body());

            $url = Storage::disk('public')->url($filename);

            Log::debug('TTS: Synthesis complete', [
                'filename' => $filename,
                'url' => $url,
                'file_size' => strlen($response->body()),
            ]);

            return SynthesisResult::success($url, $filename);
        } catch (\Throwable $e) {
            Log::error('TTS synthesis error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return SynthesisResult::failure($e->getMessage());
        }
    }
}
