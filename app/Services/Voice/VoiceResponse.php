<?php

namespace App\Services\Voice;

class VoiceResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $transcript = null,
        public readonly ?string $response = null,
        public readonly ?string $audioUrl = null,
        public readonly ?string $error = null
    ) {}

    public static function failure(string $error): self
    {
        return new self(
            success: false,
            error: $error
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'transcript' => $this->transcript,
            'response' => $this->response,
            'audio_url' => $this->audioUrl,
            'error' => $this->error,
        ];
    }
}
