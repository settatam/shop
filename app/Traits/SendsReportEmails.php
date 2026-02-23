<?php

namespace App\Traits;

use App\Mail\DynamicReportMail;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

trait SendsReportEmails
{
    /**
     * Send a report via email with the table data and CSV attachment.
     *
     * @param  array<string>  $headers  Column headers for the table/CSV
     * @param  Collection|array<array<mixed>>  $rows  Data rows
     * @param  array<mixed>  $totals  Totals row (optional)
     * @param  callable|null  $formatRow  Function to format each row for display
     * @param  Store|null  $store  Store to use for email settings (from address, reply-to)
     */
    protected function sendReportEmail(
        Request $request,
        string $reportTitle,
        string $description,
        array $headers,
        Collection|array $rows,
        array $totals = [],
        ?callable $formatRow = null,
        ?string $csvFilename = null,
        ?Store $store = null,
    ): JsonResponse {
        // Validate the request
        $validator = Validator::make($request->all(), [
            'emails' => ['required', 'array', 'min:1'],
            'emails.*' => ['required', 'email'],
            'subject' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $emails = $request->input('emails');
        $subject = $request->input('subject', $reportTitle);

        // Convert to collection if array
        if (is_array($rows)) {
            $rows = collect($rows);
        }

        // Format rows for display if formatter provided
        $displayRows = $formatRow
            ? $rows->map($formatRow)->toArray()
            : $rows->toArray();

        // Build CSV rows (raw data, formatted)
        $csvRows = $this->buildCsvRows($rows, $totals, $formatRow);

        // Build email content
        $content = [
            'headers' => $headers,
            'rows' => $displayRows,
        ];

        // Add totals row if provided
        if (! empty($totals)) {
            $formattedTotals = $formatRow ? $formatRow($totals) : $totals;
            $content['rows'][] = $formattedTotals;
        }

        // Create the mailable
        $mailable = new DynamicReportMail(
            reportTitle: $subject,
            description: $description,
            content: $content,
            rowCount: $rows->count(),
            generatedAt: Carbon::now(),
        );

        // Attach CSV
        $mailable->attachCsv(
            $headers,
            $csvRows,
            $csvFilename ?? $this->generateCsvFilename($reportTitle)
        );

        // Set from address using store settings if provided
        if ($store) {
            $fromAddress = $store->email_from_address ?: config('mail.from.address');
            $fromName = $store->email_from_name ?: config('mail.from.name', $store->name);
            $mailable->from($fromAddress, $fromName);

            if ($store->email_reply_to_address) {
                $mailable->replyTo($store->email_reply_to_address);
            }
        }

        // Send to all recipients
        try {
            foreach ($emails as $email) {
                Mail::to($email)->send(clone $mailable);
            }

            return response()->json([
                'success' => true,
                'message' => count($emails) === 1
                    ? 'Report sent successfully'
                    : 'Report sent to '.count($emails).' recipients',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send email: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Build CSV rows including totals row.
     *
     * @param  array<mixed>  $totals
     * @return array<array<mixed>>
     */
    protected function buildCsvRows(Collection $rows, array $totals, ?callable $formatRow): array
    {
        $csvRows = $formatRow
            ? $rows->map($formatRow)->toArray()
            : $rows->toArray();

        // Add totals row with "TOTALS" label
        if (! empty($totals)) {
            $formattedTotals = $formatRow ? $formatRow($totals) : $totals;
            // Replace first column with TOTALS label
            if (is_array($formattedTotals) && ! empty($formattedTotals)) {
                $keys = array_keys($formattedTotals);
                $formattedTotals[$keys[0]] = 'TOTALS';
            }
            $csvRows[] = $formattedTotals;
        }

        return $csvRows;
    }

    /**
     * Generate a CSV filename from the report title.
     */
    protected function generateCsvFilename(string $title): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9\-_]/', '-', strtolower($title));
        $sanitized = preg_replace('/-+/', '-', $sanitized);

        return trim($sanitized, '-').'-'.now()->format('Y-m-d').'.csv';
    }

    /**
     * Format a number for display.
     */
    protected function formatNumber(float|int $value): string
    {
        return number_format($value);
    }

    /**
     * Format currency for display.
     */
    protected function formatCurrency(float|int $value): string
    {
        return '$'.number_format($value, 2);
    }

    /**
     * Format percentage for display.
     */
    protected function formatPercent(float|int $value): string
    {
        return number_format($value, 2).'%';
    }
}
