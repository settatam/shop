<?php

namespace App\Mail;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DynamicReportMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * The CSV content for attachment.
     */
    protected ?string $csvContent = null;

    /**
     * The filename for the CSV attachment.
     */
    protected ?string $csvFilename = null;

    /**
     * Custom subject line.
     */
    protected ?string $customSubject = null;

    /**
     * Create a new message instance.
     *
     * @param  array{headers?: array<string>, rows?: array<array<mixed>>, html_table?: string, tables?: array}|string  $content
     */
    public function __construct(
        public string $reportTitle,
        public string $description,
        public array|string $content,
        public int $rowCount,
        public Carbon $generatedAt
    ) {}

    /**
     * Set a custom subject for the email.
     */
    public function withSubject(string $subject): self
    {
        $this->customSubject = $subject;

        return $this;
    }

    /**
     * Attach a CSV file to the email.
     *
     * @param  array<string>  $headers
     * @param  array<array<mixed>>  $rows
     */
    public function attachCsv(array $headers, array $rows, ?string $filename = null): self
    {
        $this->csvFilename = $filename ?? $this->generateCsvFilename();
        $this->csvContent = $this->generateCsvContent($headers, $rows);

        return $this;
    }

    /**
     * Generate a filename for the CSV attachment.
     */
    protected function generateCsvFilename(): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9\-_]/', '-', strtolower($this->reportTitle));
        $sanitized = preg_replace('/-+/', '-', $sanitized);

        return trim($sanitized, '-').'-'.$this->generatedAt->format('Y-m-d').'.csv';
    }

    /**
     * Generate CSV content from headers and rows.
     *
     * @param  array<string>  $headers
     * @param  array<array<mixed>>  $rows
     */
    protected function generateCsvContent(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        // Write headers
        fputcsv($handle, $headers);

        // Write rows
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->customSubject ?? "Report: {$this->reportTitle}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.dynamic-report',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if ($this->csvContent === null) {
            return [];
        }

        return [
            Attachment::fromData(fn () => $this->csvContent, $this->csvFilename)
                ->withMime('text/csv'),
        ];
    }
}
