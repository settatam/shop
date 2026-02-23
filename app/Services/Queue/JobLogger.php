<?php

namespace App\Services\Queue;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class JobLogger
{
    protected const PREFIX = 'job_logs:';

    protected const INDEX_KEY = 'job_logs:index';

    protected const TTL_DAYS = 7;

    protected static ?bool $redisAvailable = null;

    /**
     * Check if Redis is available.
     */
    protected static function isRedisAvailable(): bool
    {
        if (self::$redisAvailable !== null) {
            return self::$redisAvailable;
        }

        try {
            Redis::ping();
            self::$redisAvailable = true;
        } catch (\Exception $e) {
            self::$redisAvailable = false;
        }

        return self::$redisAvailable;
    }

    /**
     * Log when a job starts.
     */
    public static function started(string $jobClass, array $payload = [], ?int $storeId = null): string
    {
        $jobId = Str::uuid()->toString();

        if (! self::isRedisAvailable()) {
            return $jobId;
        }

        $data = [
            'id' => $jobId,
            'job' => class_basename($jobClass),
            'job_class' => $jobClass,
            'queue' => $payload['queue'] ?? 'default',
            'store_id' => $storeId,
            'status' => 'running',
            'payload' => json_encode($payload),
            'started_at' => now()->toISOString(),
            'completed_at' => null,
            'failed_at' => null,
            'duration_ms' => null,
            'error' => null,
            'result' => null,
        ];

        self::store($jobId, $data);
        self::addToIndex($jobId, $storeId);

        return $jobId;
    }

    /**
     * Log when a job completes successfully.
     */
    public static function completed(string $jobId, mixed $result = null): void
    {
        if (! self::isRedisAvailable()) {
            return;
        }

        $data = self::get($jobId);

        if (! $data) {
            return;
        }

        $startedAt = $data['started_at'] ?? null;
        $duration = $startedAt ? abs(now()->diffInMilliseconds(\Carbon\Carbon::parse($startedAt))) : null;

        $data['status'] = 'completed';
        $data['completed_at'] = now()->toISOString();
        $data['duration_ms'] = $duration;
        $data['result'] = is_string($result) ? $result : json_encode($result);

        self::store($jobId, $data);
    }

    /**
     * Log when a job fails.
     */
    public static function failed(string $jobId, \Throwable $exception): void
    {
        if (! self::isRedisAvailable()) {
            return;
        }

        $data = self::get($jobId);

        if (! $data) {
            return;
        }

        $startedAt = $data['started_at'] ?? null;
        $duration = $startedAt ? abs(now()->diffInMilliseconds(\Carbon\Carbon::parse($startedAt))) : null;

        $data['status'] = 'failed';
        $data['failed_at'] = now()->toISOString();
        $data['duration_ms'] = $duration;
        $data['error'] = [
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => array_slice($exception->getTrace(), 0, 5),
        ];

        self::store($jobId, $data);
    }

    /**
     * Get a job log by ID.
     */
    public static function get(string $jobId): ?array
    {
        if (! self::isRedisAvailable()) {
            return null;
        }

        $data = Redis::get(self::PREFIX.$jobId);

        return $data ? json_decode($data, true) : null;
    }

    /**
     * Get recent job logs.
     */
    public static function recent(int $limit = 50, ?int $storeId = null, ?string $status = null): array
    {
        if (! self::isRedisAvailable()) {
            return [];
        }

        $key = $storeId ? self::INDEX_KEY.":store:{$storeId}" : self::INDEX_KEY.':all';

        $jobIds = Redis::lrange($key, 0, $limit * 2);

        $logs = [];
        foreach ($jobIds as $jobId) {
            $log = self::get($jobId);
            if ($log) {
                if ($status === null || $log['status'] === $status) {
                    $logs[] = $log;
                }
                if (count($logs) >= $limit) {
                    break;
                }
            }
        }

        return $logs;
    }

    /**
     * Get job logs by status.
     */
    public static function byStatus(string $status, int $limit = 50): array
    {
        return self::recent($limit, null, $status);
    }

    /**
     * Get failed jobs.
     */
    public static function failed_jobs(int $limit = 50): array
    {
        return self::byStatus('failed', $limit);
    }

    /**
     * Get running jobs.
     */
    public static function running(int $limit = 50): array
    {
        return self::byStatus('running', $limit);
    }

    /**
     * Get job statistics.
     */
    public static function stats(?int $storeId = null): array
    {
        $logs = self::recent(1000, $storeId);

        $stats = [
            'total' => count($logs),
            'completed' => 0,
            'failed' => 0,
            'running' => 0,
            'avg_duration_ms' => 0,
        ];

        $durations = [];

        foreach ($logs as $log) {
            $stats[$log['status']] = ($stats[$log['status']] ?? 0) + 1;
            if ($log['duration_ms']) {
                $durations[] = $log['duration_ms'];
            }
        }

        if (count($durations) > 0) {
            $stats['avg_duration_ms'] = round(array_sum($durations) / count($durations));
        }

        return $stats;
    }

    /**
     * Clear old logs (can be called from scheduler).
     */
    public static function cleanup(int $olderThanDays = 7): int
    {
        if (! self::isRedisAvailable()) {
            return 0;
        }

        $cutoff = now()->subDays($olderThanDays);
        $deleted = 0;

        $allJobIds = Redis::lrange(self::INDEX_KEY.':all', 0, -1);

        foreach ($allJobIds as $jobId) {
            $log = self::get($jobId);
            if ($log) {
                $createdAt = $log['started_at'] ?? null;
                if ($createdAt && now()->parse($createdAt)->lt($cutoff)) {
                    Redis::del(self::PREFIX.$jobId);
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    protected static function store(string $jobId, array $data): void
    {
        $ttl = self::TTL_DAYS * 24 * 60 * 60;
        Redis::setex(self::PREFIX.$jobId, $ttl, json_encode($data));
    }

    protected static function addToIndex(string $jobId, ?int $storeId): void
    {
        // Global index
        Redis::lpush(self::INDEX_KEY.':all', $jobId);
        Redis::ltrim(self::INDEX_KEY.':all', 0, 9999);

        // Store-specific index
        if ($storeId) {
            Redis::lpush(self::INDEX_KEY.":store:{$storeId}", $jobId);
            Redis::ltrim(self::INDEX_KEY.":store:{$storeId}", 0, 999);
        }
    }
}
