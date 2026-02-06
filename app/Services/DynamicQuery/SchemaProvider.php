<?php

namespace App\Services\DynamicQuery;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class SchemaProvider
{
    /**
     * Get the database schema for allowed tables.
     *
     * @return array<string, array{columns: array, indexes: array, foreign_keys: array}>
     */
    public function getSchema(int $storeId): array
    {
        $ttl = config('dynamic-query.cache.schema_ttl', 3600);

        return Cache::remember("dynamic_query_schema:{$storeId}", $ttl, function () {
            return $this->buildSchema();
        });
    }

    /**
     * Build the schema array for all allowed tables.
     *
     * @return array<string, array{columns: array, indexes: array, foreign_keys: array}>
     */
    protected function buildSchema(): array
    {
        $allowedTables = config('dynamic-query.allowed_tables', []);
        $blockedColumns = config('dynamic-query.blocked_columns', []);
        $schema = [];

        foreach ($allowedTables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $schema[$table] = [
                'columns' => $this->getTableColumns($table, $blockedColumns),
                'indexes' => $this->getTableIndexes($table),
                'foreign_keys' => $this->getTableForeignKeys($table),
            ];
        }

        return $schema;
    }

    /**
     * Get column information for a table.
     *
     * @param  string[]  $blockedColumns
     * @return array<string, array{type: string, nullable: bool}>
     */
    protected function getTableColumns(string $table, array $blockedColumns): array
    {
        $columns = [];
        $columnListing = Schema::getColumns($table);

        foreach ($columnListing as $column) {
            $columnName = $column['name'];

            // Skip blocked columns
            if (in_array($columnName, $blockedColumns, true)) {
                continue;
            }

            $columns[$columnName] = [
                'type' => $column['type'] ?? 'unknown',
                'nullable' => $column['nullable'] ?? true,
            ];
        }

        return $columns;
    }

    /**
     * Get index information for a table.
     *
     * @return array<string, array{columns: array, unique: bool}>
     */
    protected function getTableIndexes(string $table): array
    {
        $indexes = [];

        try {
            $indexList = Schema::getIndexes($table);

            foreach ($indexList as $index) {
                $indexes[$index['name']] = [
                    'columns' => $index['columns'] ?? [],
                    'unique' => $index['unique'] ?? false,
                ];
            }
        } catch (\Throwable) {
            // Some drivers may not support getIndexes
        }

        return $indexes;
    }

    /**
     * Get foreign key information for a table.
     *
     * @return array<string, array{columns: array, foreign_table: string, foreign_columns: array}>
     */
    protected function getTableForeignKeys(string $table): array
    {
        $foreignKeys = [];

        try {
            $fkList = Schema::getForeignKeys($table);

            foreach ($fkList as $fk) {
                $foreignKeys[$fk['name']] = [
                    'columns' => $fk['columns'] ?? [],
                    'foreign_table' => $fk['foreign_table'] ?? '',
                    'foreign_columns' => $fk['foreign_columns'] ?? [],
                ];
            }
        } catch (\Throwable) {
            // Some drivers may not support getForeignKeys
        }

        return $foreignKeys;
    }

    /**
     * Get a formatted schema string suitable for LLM prompts.
     */
    public function getSchemaForPrompt(int $storeId): string
    {
        $schema = $this->getSchema($storeId);
        $output = [];

        foreach ($schema as $tableName => $tableInfo) {
            $lines = ["Table: {$tableName}"];
            $lines[] = 'Columns:';

            foreach ($tableInfo['columns'] as $columnName => $columnInfo) {
                $nullable = $columnInfo['nullable'] ? 'NULL' : 'NOT NULL';
                $lines[] = "  - {$columnName} ({$columnInfo['type']}, {$nullable})";
            }

            if (! empty($tableInfo['foreign_keys'])) {
                $lines[] = 'Foreign Keys:';
                foreach ($tableInfo['foreign_keys'] as $fkName => $fkInfo) {
                    $localCols = implode(', ', $fkInfo['columns']);
                    $foreignCols = implode(', ', $fkInfo['foreign_columns']);
                    $lines[] = "  - {$localCols} -> {$fkInfo['foreign_table']}.{$foreignCols}";
                }
            }

            $output[] = implode("\n", $lines);
        }

        return implode("\n\n", $output);
    }

    /**
     * Clear the cached schema for a store.
     */
    public function clearCache(int $storeId): void
    {
        Cache::forget("dynamic_query_schema:{$storeId}");
    }

    /**
     * Get the list of allowed tables.
     *
     * @return string[]
     */
    public function getAllowedTables(): array
    {
        return config('dynamic-query.allowed_tables', []);
    }
}
