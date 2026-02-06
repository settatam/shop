<?php

namespace App\Services\DynamicQuery;

use App\Services\AI\AIManager;

class QueryGenerator
{
    public function __construct(
        protected AIManager $ai,
        protected SchemaProvider $schemaProvider
    ) {}

    /**
     * Generate a SQL query from a natural language request.
     *
     * @return array{sql: string, explanation: string, columns: string[]}
     */
    public function generate(string $request, int $storeId): array
    {
        $schemaText = $this->schemaProvider->getSchemaForPrompt($storeId);
        $prompt = $this->buildPrompt($request, $schemaText, $storeId);

        $response = $this->ai->generateJson($prompt, $this->getResponseSchema(), [
            'feature' => 'dynamic_query',
        ]);

        $result = $response->content;

        return [
            'sql' => $result['sql'] ?? '',
            'explanation' => $result['explanation'] ?? '',
            'columns' => $result['columns'] ?? [],
        ];
    }

    /**
     * Build the prompt for SQL generation.
     */
    protected function buildPrompt(string $request, string $schema, int $storeId): string
    {
        return <<<PROMPT
You are a SQL query generator for a retail/jewelry store management system. Generate a safe, read-only MySQL SELECT query based on the user's request.

## Database Schema
{$schema}

## Important Rules
1. Generate ONLY SELECT queries - no INSERT, UPDATE, DELETE, DROP, etc.
2. All tables have a `store_id` column for multi-tenant isolation. Always include `store_id = {$storeId}` in your WHERE clause.
3. Use proper JOINs when relating tables.
4. For date filtering, use MySQL date functions like CURDATE(), NOW(), DATE_SUB(), etc.
5. Include reasonable ORDER BY clauses for meaningful results.
6. Use SUM, COUNT, AVG, etc. for aggregations when appropriate.
7. Alias columns for clarity in the output.
8. For order/transaction status filtering, common statuses include: 'pending', 'completed', 'cancelled', 'shipped', 'delivered'.
9. When asked about "sales", typically query the `orders` table for completed orders.
10. When asked about "products not sold", use NOT IN or NOT EXISTS with order_items.

## User Request
{$request}

Generate the SQL query that best answers this request. Return valid MySQL syntax.
PROMPT;
    }

    /**
     * Get the JSON schema for the AI response.
     *
     * @return array<string, mixed>
     */
    protected function getResponseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sql' => [
                    'type' => 'string',
                    'description' => 'The generated SQL query',
                ],
                'explanation' => [
                    'type' => 'string',
                    'description' => 'A brief explanation of what the query does',
                ],
                'columns' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'List of column names/aliases that will be returned',
                ],
            ],
            'required' => ['sql', 'explanation', 'columns'],
        ];
    }
}
