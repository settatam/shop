<?php

namespace App\Services\DynamicQuery;

use App\Mail\DynamicReportMail;
use Illuminate\Support\Facades\Mail;

class DynamicQueryService
{
    public function __construct(
        protected QueryGenerator $generator,
        protected QueryValidator $validator,
        protected QueryExecutor $executor,
        protected ReportFormatter $formatter
    ) {}

    /**
     * Execute a dynamic query from a natural language request.
     *
     * @return array{
     *     success: bool,
     *     data: array,
     *     explanation: string,
     *     summary: string,
     *     error: ?string,
     *     sql: ?string,
     *     row_count: int,
     *     execution_time_ms: int,
     *     delivered_via: ?string
     * }
     */
    public function query(
        string $request,
        int $storeId,
        string $format = 'display',
        ?string $deliveryMethod = null,
        ?string $emailAddress = null
    ): array {
        try {
            // Step 1: Generate SQL from natural language
            $generated = $this->generator->generate($request, $storeId);

            if (empty($generated['sql'])) {
                return $this->errorResult('Failed to generate SQL query from the request');
            }

            // Step 2: Validate the generated SQL
            $validation = $this->validator->validate($generated['sql'], $storeId);

            if (! $validation['valid']) {
                return $this->errorResult(
                    'Query validation failed: '.implode(', ', $validation['errors']),
                    $generated['sql']
                );
            }

            // Step 3: Execute the validated query
            $execution = $this->executor->execute($validation['sql']);

            if (! $execution['success']) {
                return $this->errorResult(
                    'Query execution failed: '.$execution['error'],
                    $validation['sql']
                );
            }

            // Step 4: Format the results
            $formatted = $this->formatter->format(
                $execution['data'],
                $generated['columns'],
                $format
            );

            // Step 5: Deliver results (email if requested)
            $deliveredVia = null;
            if ($deliveryMethod === 'email' && $emailAddress) {
                $this->sendEmail(
                    $emailAddress,
                    $request,
                    $formatted,
                    $generated['explanation'],
                    $execution['row_count']
                );
                $deliveredVia = 'email';
            }

            return [
                'success' => true,
                'data' => $formatted['content'],
                'explanation' => $generated['explanation'],
                'summary' => $formatted['summary'],
                'error' => null,
                'sql' => $validation['sql'],
                'row_count' => $execution['row_count'],
                'truncated' => $execution['truncated'],
                'execution_time_ms' => $execution['execution_time_ms'],
                'delivered_via' => $deliveredVia,
            ];
        } catch (\Throwable $e) {
            return $this->errorResult($e->getMessage());
        }
    }

    /**
     * Send the report via email.
     *
     * @param  array{format: string, content: mixed, summary: string}  $formatted
     */
    protected function sendEmail(
        string $emailAddress,
        string $request,
        array $formatted,
        string $explanation,
        int $rowCount
    ): void {
        Mail::to($emailAddress)->queue(new DynamicReportMail(
            reportTitle: $this->generateReportTitle($request),
            description: $explanation,
            content: $formatted['content'],
            rowCount: $rowCount,
            generatedAt: now()
        ));
    }

    /**
     * Generate a report title from the request.
     */
    protected function generateReportTitle(string $request): string
    {
        // Capitalize and clean up the request for use as a title
        $title = ucfirst(trim($request));

        // Remove question marks and truncate if too long
        $title = rtrim($title, '?');

        if (strlen($title) > 100) {
            $title = substr($title, 0, 97).'...';
        }

        return $title;
    }

    /**
     * Build an error result array.
     *
     * @return array{
     *     success: bool,
     *     data: array,
     *     explanation: string,
     *     summary: string,
     *     error: string,
     *     sql: ?string,
     *     row_count: int,
     *     execution_time_ms: int,
     *     delivered_via: ?string
     * }
     */
    protected function errorResult(string $error, ?string $sql = null): array
    {
        return [
            'success' => false,
            'data' => [],
            'explanation' => '',
            'summary' => 'Query failed',
            'error' => $error,
            'sql' => $sql,
            'row_count' => 0,
            'truncated' => false,
            'execution_time_ms' => 0,
            'delivered_via' => null,
        ];
    }
}
