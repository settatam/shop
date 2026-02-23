<?php

namespace App\Console\Commands;

use App\Services\Queue\JobLogger;
use Illuminate\Console\Command;

class ViewJobLogsCommand extends Command
{
    protected $signature = 'jobs:logs
                            {--limit=20 : Number of logs to show}
                            {--status= : Filter by status (running, completed, failed)}
                            {--store= : Filter by store ID}
                            {--stats : Show statistics only}';

    protected $description = 'View job execution logs from Redis';

    public function handle(): int
    {
        if ($this->option('stats')) {
            return $this->showStats();
        }

        $limit = (int) $this->option('limit');
        $status = $this->option('status');
        $storeId = $this->option('store') ? (int) $this->option('store') : null;

        $logs = JobLogger::recent($limit, $storeId, $status);

        if (empty($logs)) {
            $this->info('No job logs found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Job', 'Status', 'Store', 'Duration', 'Started At', 'Error'],
            collect($logs)->map(function ($log) {
                $status = match ($log['status']) {
                    'completed' => '<fg=green>completed</>',
                    'failed' => '<fg=red>failed</>',
                    'running' => '<fg=yellow>running</>',
                    default => $log['status'],
                };

                $duration = $log['duration_ms']
                    ? ($log['duration_ms'] > 1000
                        ? round($log['duration_ms'] / 1000, 2).'s'
                        : $log['duration_ms'].'ms')
                    : '-';

                $error = $log['error']['message'] ?? '-';
                if (strlen($error) > 40) {
                    $error = substr($error, 0, 40).'...';
                }

                return [
                    $log['job'],
                    $status,
                    $log['store_id'] ?? '-',
                    $duration,
                    \Carbon\Carbon::parse($log['started_at'])->diffForHumans(),
                    $error,
                ];
            })->toArray()
        );

        return self::SUCCESS;
    }

    protected function showStats(): int
    {
        $storeId = $this->option('store') ? (int) $this->option('store') : null;
        $stats = JobLogger::stats($storeId);

        $this->info('Job Statistics'.($storeId ? " (Store #{$storeId})" : ''));
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Jobs', $stats['total']],
                ['Completed', "<fg=green>{$stats['completed']}</>"],
                ['Failed', "<fg=red>{$stats['failed']}</>"],
                ['Running', "<fg=yellow>{$stats['running']}</>"],
                ['Avg Duration', $stats['avg_duration_ms'].'ms'],
            ]
        );

        if ($stats['total'] > 0) {
            $successRate = round(($stats['completed'] / $stats['total']) * 100, 1);
            $this->newLine();
            $this->info("Success Rate: {$successRate}%");
        }

        return self::SUCCESS;
    }
}
