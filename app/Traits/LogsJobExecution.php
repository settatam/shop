<?php

namespace App\Traits;

use App\Services\Queue\JobLogger;

trait LogsJobExecution
{
    protected ?string $jobLogId = null;

    /**
     * Get the store ID for this job (override in job class if needed).
     */
    protected function getStoreIdForLogging(): ?int
    {
        return $this->storeId ?? $this->store_id ?? $this->store?->id ?? null;
    }

    /**
     * Get the payload for logging (override to customize).
     */
    protected function getPayloadForLogging(): array
    {
        return [
            'queue' => $this->queue ?? 'default',
        ];
    }

    /**
     * Start logging this job execution.
     */
    protected function startJobLog(): void
    {
        $this->jobLogId = JobLogger::started(
            static::class,
            $this->getPayloadForLogging(),
            $this->getStoreIdForLogging()
        );
    }

    /**
     * Complete the job log with optional result.
     */
    protected function completeJobLog(mixed $result = null): void
    {
        if ($this->jobLogId) {
            JobLogger::completed($this->jobLogId, $result);
        }
    }

    /**
     * Mark the job log as failed.
     */
    protected function failJobLog(\Throwable $exception): void
    {
        if ($this->jobLogId) {
            JobLogger::failed($this->jobLogId, $exception);
        }
    }
}
