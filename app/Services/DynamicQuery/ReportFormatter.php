<?php

namespace App\Services\DynamicQuery;

class ReportFormatter
{
    /**
     * Format query results for different output types.
     *
     * @param  array<int, array<string, mixed>>  $data
     * @param  string[]  $columns
     * @return array{format: string, content: mixed, summary: string}
     */
    public function format(array $data, array $columns, string $format = 'display'): array
    {
        return match ($format) {
            'display' => $this->formatForDisplay($data, $columns),
            'voice' => $this->formatForVoice($data, $columns),
            'email' => $this->formatForEmail($data, $columns),
            'csv' => $this->formatAsCsv($data, $columns),
            'summary' => $this->formatAsSummary($data, $columns),
            default => $this->formatForDisplay($data, $columns),
        };
    }

    /**
     * Format results for display in UI or chat.
     *
     * @param  array<int, array<string, mixed>>  $data
     * @param  string[]  $columns
     * @return array{format: string, content: array{headers: array, rows: array}, summary: string}
     */
    protected function formatForDisplay(array $data, array $columns): array
    {
        $headers = ! empty($data) ? array_keys($data[0]) : $columns;

        $rows = array_map(function ($row) {
            return array_map(fn ($value) => $this->formatValue($value), $row);
        }, $data);

        return [
            'format' => 'display',
            'content' => [
                'headers' => $headers,
                'rows' => $rows,
            ],
            'summary' => $this->generateSummary($data),
        ];
    }

    /**
     * Format results for voice/audio readout.
     *
     * @param  array<int, array<string, mixed>>  $data
     * @param  string[]  $columns
     * @return array{format: string, content: string, summary: string}
     */
    protected function formatForVoice(array $data, array $columns): array
    {
        $count = count($data);

        if ($count === 0) {
            return [
                'format' => 'voice',
                'content' => 'No results found for your query.',
                'summary' => 'No results',
            ];
        }

        $lines = [];

        if ($count === 1) {
            $lines[] = 'I found 1 result.';
        } else {
            $lines[] = "I found {$count} results.";
        }

        // For voice, read out first few results or summary
        $maxVoiceResults = min($count, 5);

        for ($i = 0; $i < $maxVoiceResults; $i++) {
            $row = $data[$i];
            $rowDescription = $this->describeRowForVoice($row, $i + 1);
            $lines[] = $rowDescription;
        }

        if ($count > $maxVoiceResults) {
            $remaining = $count - $maxVoiceResults;
            $lines[] = "And {$remaining} more results.";
        }

        return [
            'format' => 'voice',
            'content' => implode(' ', $lines),
            'summary' => $this->generateSummary($data),
        ];
    }

    /**
     * Format results for email delivery.
     *
     * @param  array<int, array<string, mixed>>  $data
     * @param  string[]  $columns
     * @return array{format: string, content: array{headers: array, rows: array, html_table: string}, summary: string}
     */
    protected function formatForEmail(array $data, array $columns): array
    {
        $headers = ! empty($data) ? array_keys($data[0]) : $columns;

        $rows = array_map(function ($row) {
            return array_map(fn ($value) => $this->formatValue($value), $row);
        }, $data);

        // Build HTML table for email
        $htmlTable = $this->buildHtmlTable($headers, $rows);

        return [
            'format' => 'email',
            'content' => [
                'headers' => $headers,
                'rows' => $rows,
                'html_table' => $htmlTable,
            ],
            'summary' => $this->generateSummary($data),
        ];
    }

    /**
     * Format results as CSV.
     *
     * @param  array<int, array<string, mixed>>  $data
     * @param  string[]  $columns
     * @return array{format: string, content: string, summary: string}
     */
    protected function formatAsCsv(array $data, array $columns): array
    {
        if (empty($data)) {
            return [
                'format' => 'csv',
                'content' => '',
                'summary' => 'No results',
            ];
        }

        $output = fopen('php://temp', 'r+');
        if ($output === false) {
            return [
                'format' => 'csv',
                'content' => '',
                'summary' => 'Error generating CSV',
            ];
        }

        // Write headers
        fputcsv($output, array_keys($data[0]));

        // Write data rows
        foreach ($data as $row) {
            fputcsv($output, array_values($row));
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return [
            'format' => 'csv',
            'content' => $csv ?: '',
            'summary' => $this->generateSummary($data),
        ];
    }

    /**
     * Format results as a text summary only.
     *
     * @param  array<int, array<string, mixed>>  $data
     * @param  string[]  $columns
     * @return array{format: string, content: string, summary: string}
     */
    protected function formatAsSummary(array $data, array $columns): array
    {
        $summary = $this->generateSummary($data);

        return [
            'format' => 'summary',
            'content' => $summary,
            'summary' => $summary,
        ];
    }

    /**
     * Format a single value for display.
     */
    protected function formatValue(mixed $value): string
    {
        if ($value === null) {
            return '-';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_numeric($value)) {
            // Format as currency if it looks like money (has decimal places)
            if (is_float($value) || (is_string($value) && strpos($value, '.') !== false)) {
                $floatVal = (float) $value;
                if ($floatVal >= 1 && $floatVal == round($floatVal, 2)) {
                    return '$'.number_format($floatVal, 2);
                }
            }

            return (string) $value;
        }

        return (string) $value;
    }

    /**
     * Describe a row for voice readout.
     *
     * @param  array<string, mixed>  $row
     */
    protected function describeRowForVoice(array $row, int $position): string
    {
        $parts = [];

        foreach ($row as $key => $value) {
            $formattedValue = $this->formatValue($value);
            $readableKey = $this->makeKeyReadable($key);
            $parts[] = "{$readableKey}: {$formattedValue}";
        }

        return "Result {$position}: ".implode(', ', array_slice($parts, 0, 4)).'.';
    }

    /**
     * Make a column key readable for voice.
     */
    protected function makeKeyReadable(string $key): string
    {
        // Convert snake_case to Title Case
        $readable = str_replace('_', ' ', $key);

        return ucwords($readable);
    }

    /**
     * Generate a summary of the results.
     *
     * @param  array<int, array<string, mixed>>  $data
     */
    protected function generateSummary(array $data): string
    {
        $count = count($data);

        if ($count === 0) {
            return 'No results found';
        }

        if ($count === 1) {
            return '1 result found';
        }

        return "{$count} results found";
    }

    /**
     * Build an HTML table for email.
     *
     * @param  string[]  $headers
     * @param  array<int, array<string, string>>  $rows
     */
    protected function buildHtmlTable(array $headers, array $rows): string
    {
        $html = '<table style="width: 100%; border-collapse: collapse; font-size: 14px;">';

        // Header row
        $html .= '<thead><tr style="background-color: #f3f4f6;">';
        foreach ($headers as $header) {
            $readable = ucwords(str_replace('_', ' ', $header));
            $html .= '<th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb; font-weight: 600; color: #374151;">'.htmlspecialchars($readable).'</th>';
        }
        $html .= '</tr></thead>';

        // Data rows
        $html .= '<tbody>';
        foreach ($rows as $index => $row) {
            $bgColor = $index % 2 === 0 ? '#ffffff' : '#f9fafb';
            $html .= '<tr style="background-color: '.$bgColor.';">';
            foreach ($row as $value) {
                $html .= '<td style="padding: 12px; border-bottom: 1px solid #e5e7eb; color: #1f2937;">'.htmlspecialchars($value).'</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        return $html;
    }
}
