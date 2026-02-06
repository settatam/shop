<?php

namespace App\Services\DynamicQuery;

use Illuminate\Support\Facades\DB;

class QueryExecutor
{
    protected int $maxRows;

    protected int $timeout;

    protected ?string $connection;

    public function __construct()
    {
        $this->maxRows = config('dynamic-query.limits.max_rows', 1000);
        $this->timeout = config('dynamic-query.limits.query_timeout', 10);
        $this->connection = config('dynamic-query.connection');
    }

    /**
     * Execute a validated SQL query.
     *
     * @return array{success: bool, data: array, row_count: int, truncated: bool, error: ?string, execution_time_ms: int}
     */
    public function execute(string $sql): array
    {
        $startTime = microtime(true);

        try {
            // Ensure LIMIT is applied to prevent excessive results
            $sql = $this->ensureLimit($sql);

            // Get the database connection
            $db = $this->connection ? DB::connection($this->connection) : DB::connection();

            // Set query timeout
            $this->setQueryTimeout($db);

            // Execute the query
            $results = $db->select($sql);

            $executionTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            // Convert to array
            $data = array_map(fn ($row) => (array) $row, $results);
            $rowCount = count($data);
            $truncated = $rowCount >= $this->maxRows;

            return [
                'success' => true,
                'data' => $data,
                'row_count' => $rowCount,
                'truncated' => $truncated,
                'error' => null,
                'execution_time_ms' => $executionTimeMs,
            ];
        } catch (\Throwable $e) {
            $executionTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            return [
                'success' => false,
                'data' => [],
                'row_count' => 0,
                'truncated' => false,
                'error' => $this->sanitizeErrorMessage($e->getMessage()),
                'execution_time_ms' => $executionTimeMs,
            ];
        }
    }

    /**
     * Ensure the query has a LIMIT clause.
     */
    protected function ensureLimit(string $sql): string
    {
        // Check if LIMIT already exists
        if (preg_match('/\bLIMIT\s+\d+/i', $sql)) {
            // Extract the existing limit and cap it
            return preg_replace_callback('/\bLIMIT\s+(\d+)/i', function ($matches) {
                $existingLimit = (int) $matches[1];
                $limit = min($existingLimit, $this->maxRows);

                return "LIMIT {$limit}";
            }, $sql) ?? $sql;
        }

        // Add LIMIT clause
        return rtrim($sql, ';')." LIMIT {$this->maxRows}";
    }

    /**
     * Set the query timeout on the connection.
     *
     * @param  \Illuminate\Database\Connection  $connection
     */
    protected function setQueryTimeout($connection): void
    {
        $driver = $connection->getDriverName();

        switch ($driver) {
            case 'mysql':
            case 'mariadb':
                $connection->statement('SET SESSION MAX_EXECUTION_TIME = '.($this->timeout * 1000));
                break;

            case 'pgsql':
                $connection->statement("SET statement_timeout = '".($this->timeout * 1000)."ms'");
                break;
                // SQLite doesn't support query timeout, rely on PHP timeout
        }
    }

    /**
     * Sanitize error messages to avoid leaking sensitive information.
     */
    protected function sanitizeErrorMessage(string $message): string
    {
        // Remove potential file paths
        $message = preg_replace('/\/[a-zA-Z0-9_\-\/\.]+/', '[path]', $message) ?? $message;

        // Remove IP addresses
        $message = preg_replace('/\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/', '[ip]', $message) ?? $message;

        // Truncate long messages
        if (strlen($message) > 200) {
            $message = substr($message, 0, 200).'...';
        }

        return $message;
    }
}
