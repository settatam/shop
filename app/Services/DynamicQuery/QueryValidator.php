<?php

namespace App\Services\DynamicQuery;

class QueryValidator
{
    /**
     * Dangerous SQL keywords that should never appear in a query.
     *
     * @var string[]
     */
    protected const DANGEROUS_KEYWORDS = [
        'INSERT',
        'UPDATE',
        'DELETE',
        'DROP',
        'TRUNCATE',
        'ALTER',
        'CREATE',
        'REPLACE',
        'RENAME',
        'GRANT',
        'REVOKE',
        'LOCK',
        'UNLOCK',
        'LOAD',
        'CALL',
        'EXECUTE',
        'EXEC',
        'SET',
        'SLEEP',
        'BENCHMARK',
        'INTO OUTFILE',
        'INTO DUMPFILE',
    ];

    /**
     * @var string[]
     */
    protected array $allowedTables;

    /**
     * @var string[]
     */
    protected array $errors = [];

    public function __construct()
    {
        $this->allowedTables = config('dynamic-query.allowed_tables', []);
    }

    /**
     * Validate a SQL query for safety.
     *
     * @return array{valid: bool, sql: string, errors: string[]}
     */
    public function validate(string $sql, int $storeId): array
    {
        $this->errors = [];
        $normalizedSql = $this->normalizeSql($sql);

        // Check for dangerous keywords first
        if (! $this->isDangerousFree($normalizedSql)) {
            return $this->result(false, $sql);
        }

        // Ensure it's a SELECT query
        if (! $this->isSelectOnly($normalizedSql)) {
            $this->errors[] = 'Only SELECT queries are allowed';

            return $this->result(false, $sql);
        }

        // Extract and validate tables
        $tables = $this->extractTables($normalizedSql);
        if (! $this->areTablesAllowed($tables)) {
            return $this->result(false, $sql);
        }

        // Check if store_id scoping is needed and inject if missing
        $modifiedSql = $this->ensureStoreIdScoping($sql, $storeId, $tables);

        return $this->result(true, $modifiedSql);
    }

    /**
     * Normalize SQL for consistent parsing.
     */
    protected function normalizeSql(string $sql): string
    {
        // Remove comments
        $sql = preg_replace('/--.*$/m', '', $sql) ?? $sql;
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql) ?? $sql;

        // Normalize whitespace
        $sql = preg_replace('/\s+/', ' ', $sql) ?? $sql;

        return strtoupper(trim($sql));
    }

    /**
     * Check if the SQL contains any dangerous keywords.
     */
    protected function isDangerousFree(string $normalizedSql): bool
    {
        foreach (self::DANGEROUS_KEYWORDS as $keyword) {
            // Use word boundary to avoid false positives
            $pattern = '/\b'.preg_quote($keyword, '/').'\b/i';
            if (preg_match($pattern, $normalizedSql)) {
                $this->errors[] = "Dangerous keyword detected: {$keyword}";

                return false;
            }
        }

        return true;
    }

    /**
     * Check if the query is a SELECT-only query.
     */
    protected function isSelectOnly(string $normalizedSql): bool
    {
        // Must start with SELECT or WITH (for CTEs)
        return (bool) preg_match('/^(SELECT|WITH)\s/i', $normalizedSql);
    }

    /**
     * Extract table names from the SQL query.
     *
     * @return string[]
     */
    protected function extractTables(string $normalizedSql): array
    {
        $tables = [];

        // Match tables after FROM
        if (preg_match_all('/\bFROM\s+([`\w]+)/i', $normalizedSql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        // Match tables after JOIN
        if (preg_match_all('/\bJOIN\s+([`\w]+)/i', $normalizedSql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        // Remove backticks and lowercase
        $tables = array_map(function ($table) {
            return strtolower(trim($table, '`'));
        }, $tables);

        return array_unique($tables);
    }

    /**
     * Check if all extracted tables are in the allowed list.
     *
     * @param  string[]  $tables
     */
    protected function areTablesAllowed(array $tables): bool
    {
        foreach ($tables as $table) {
            if (! in_array($table, $this->allowedTables, true)) {
                $this->errors[] = "Table not allowed: {$table}";

                return false;
            }
        }

        return true;
    }

    /**
     * Ensure store_id is present in the query for proper multi-tenant scoping.
     *
     * @param  string[]  $tables
     */
    protected function ensureStoreIdScoping(string $sql, int $storeId, array $tables): string
    {
        // Check if store_id is already in the query
        if (preg_match('/\bstore_id\s*(=|IN)/i', $sql)) {
            // Replace any existing store_id placeholder with the actual value
            return preg_replace('/store_id\s*=\s*\?/', "store_id = {$storeId}", $sql) ?? $sql;
        }

        // If no store_id present, we need to add it
        // Find the first table that has a store_id column (most likely all allowed tables have it)
        $primaryTable = $tables[0] ?? null;

        if (! $primaryTable) {
            return $sql;
        }

        // Check if WHERE clause exists
        if (preg_match('/\bWHERE\b/i', $sql)) {
            // Add store_id to existing WHERE clause
            $sql = preg_replace(
                '/\bWHERE\b/i',
                "WHERE {$primaryTable}.store_id = {$storeId} AND",
                $sql,
                1
            ) ?? $sql;
        } else {
            // Add WHERE clause before GROUP BY, HAVING, ORDER BY, or LIMIT, or at the end
            $insertPatterns = [
                '/\bGROUP\s+BY\b/i',
                '/\bHAVING\b/i',
                '/\bORDER\s+BY\b/i',
                '/\bLIMIT\b/i',
            ];

            $inserted = false;
            foreach ($insertPatterns as $pattern) {
                if (preg_match($pattern, $sql, $matches, PREG_OFFSET_CAPTURE)) {
                    $position = $matches[0][1];
                    $sql = substr($sql, 0, $position)."WHERE {$primaryTable}.store_id = {$storeId} ".substr($sql, $position);
                    $inserted = true;
                    break;
                }
            }

            if (! $inserted) {
                // Add at the end
                $sql .= " WHERE {$primaryTable}.store_id = {$storeId}";
            }
        }

        return $sql;
    }

    /**
     * Build the validation result array.
     *
     * @return array{valid: bool, sql: string, errors: string[]}
     */
    protected function result(bool $valid, string $sql): array
    {
        return [
            'valid' => $valid,
            'sql' => $sql,
            'errors' => $this->errors,
        ];
    }

    /**
     * Get the allowed tables list.
     *
     * @return string[]
     */
    public function getAllowedTables(): array
    {
        return $this->allowedTables;
    }
}
