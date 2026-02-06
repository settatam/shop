<?php

namespace App\Services\Chat\Tools;

use App\Services\DynamicQuery\DynamicQueryService;

class DynamicQueryTool implements ChatToolInterface
{
    public function __construct(
        protected DynamicQueryService $queryService
    ) {}

    public function name(): string
    {
        return 'run_dynamic_query';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Run a custom database query based on natural language. Use when the user asks for specific data, custom reports, or analysis not covered by other tools. Can display results directly or email them. Examples: "Show me yesterday\'s sales", "Email me customers who spent over $1000 this month", "What products haven\'t sold in 90 days?"',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query_description' => [
                        'type' => 'string',
                        'description' => 'Natural language description of what data the user wants. Be specific about filters, date ranges, and aggregations.',
                    ],
                    'delivery_method' => [
                        'type' => 'string',
                        'enum' => ['display', 'email'],
                        'description' => 'How to deliver the results. Use "display" to show in chat, "email" to send via email.',
                    ],
                    'email_address' => [
                        'type' => 'string',
                        'description' => 'Email address to send the report to. Required if delivery_method is "email".',
                    ],
                    'format' => [
                        'type' => 'string',
                        'enum' => ['display', 'summary', 'csv'],
                        'description' => 'Output format. "display" shows a table, "summary" gives counts/totals only, "csv" for downloadable data.',
                    ],
                ],
                'required' => ['query_description'],
            ],
        ];
    }

    public function execute(array $params, int $storeId): array
    {
        $queryDescription = $params['query_description'] ?? '';
        $deliveryMethod = $params['delivery_method'] ?? 'display';
        $emailAddress = $params['email_address'] ?? null;
        $format = $params['format'] ?? 'display';

        // Validate email if delivery method is email
        if ($deliveryMethod === 'email' && empty($emailAddress)) {
            return [
                'success' => false,
                'error' => 'Email address is required when delivery method is email',
            ];
        }

        // Map format for email delivery
        if ($deliveryMethod === 'email') {
            $format = 'email';
        }

        // Execute the dynamic query
        $result = $this->queryService->query(
            request: $queryDescription,
            storeId: $storeId,
            format: $format,
            deliveryMethod: $deliveryMethod,
            emailAddress: $emailAddress
        );

        if (! $result['success']) {
            return [
                'success' => false,
                'error' => $result['error'],
            ];
        }

        // Build the response
        $response = [
            'success' => true,
            'explanation' => $result['explanation'],
            'row_count' => $result['row_count'],
            'summary' => $result['summary'],
        ];

        // Add delivery information
        if ($result['delivered_via'] === 'email') {
            $response['message'] = "Report sent to {$emailAddress}";
            $response['delivered_via'] = 'email';
        } else {
            $response['data'] = $result['data'];
            $response['delivered_via'] = 'display';
        }

        // Add truncation warning if applicable
        if ($result['truncated'] ?? false) {
            $response['truncation_warning'] = 'Results were limited to '.config('dynamic-query.limits.max_rows', 1000).' rows';
        }

        return $response;
    }
}
