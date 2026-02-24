<?php

namespace App\Console\Commands;

use App\Mail\DynamicReportMail;
use App\Models\ScheduledReport;
use App\Services\Reports\ReportRegistry;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendScheduledReports extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reports:send-scheduled
                            {--time= : Specific time to process (HH:MM format, defaults to current)}
                            {--store-id= : Process reports for specific store only}
                            {--report-id= : Process a specific scheduled report}
                            {--dry-run : Show what would be sent without actually sending}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send scheduled reports that are due at the current time';

    public function __construct(
        protected ReportRegistry $reportRegistry,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $time = $this->option('time') ?? Carbon::now()->format('H:i');
        $storeId = $this->option('store-id');
        $reportId = $this->option('report-id');
        $dryRun = $this->option('dry-run');

        $this->info("Processing scheduled reports for time: {$time}");

        $query = ScheduledReport::query()
            ->with('store')
            ->enabled();

        if ($reportId) {
            $query->where('id', $reportId);
        } else {
            $query->where('schedule_time', $time);
        }

        if ($storeId) {
            $query->where('store_id', $storeId);
        }

        $scheduledReports = $query->get();

        if ($scheduledReports->isEmpty()) {
            $this->info('No scheduled reports found for this time.');

            return self::SUCCESS;
        }

        $this->info("Found {$scheduledReports->count()} scheduled report(s) to process.");

        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($scheduledReports as $scheduledReport) {
            $this->line('');
            $this->info("Processing: {$scheduledReport->display_name} (ID: {$scheduledReport->id})");
            $this->line("  Store: {$scheduledReport->store->name}");
            $this->line('  Recipients: '.implode(', ', $scheduledReport->recipients));

            // Check if report should run today based on schedule_days
            if (! $scheduledReport->shouldRunToday()) {
                $this->line('  <comment>Skipped: Not scheduled for today</comment>');
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line('  <comment>Dry run: Would send report</comment>');
                $sent++;

                continue;
            }

            try {
                $this->sendReport($scheduledReport);
                $this->line('  <info>Sent successfully</info>');
                $sent++;
            } catch (\Exception $e) {
                $this->error("  Failed: {$e->getMessage()}");
                $failed++;

                // Update the scheduled report with failure info
                $scheduledReport->update([
                    'last_failed_at' => now(),
                    'last_error' => $e->getMessage(),
                ]);
            }
        }

        $this->line('');
        $this->info("Summary: {$sent} sent, {$skipped} skipped, {$failed} failed");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Send a single scheduled report.
     */
    protected function sendReport(ScheduledReport $scheduledReport): void
    {
        $store = $scheduledReport->store;

        // Generate the report for yesterday in the report's timezone
        $reportDate = Carbon::yesterday($scheduledReport->timezone);
        $report = $this->reportRegistry->makeReport($scheduledReport->report_type, $store, $reportDate);

        if (! $report) {
            throw new \RuntimeException("Report type '{$scheduledReport->report_type}' not found");
        }

        $data = $report->getData();
        $structure = $report->getStructure();

        // Build email content
        $structureArray = $structure->toArray();
        $tables = [];
        $totalRowCount = 0;

        foreach ($structureArray['tables'] as $table) {
            $dataKey = $table['data_key'] ?? $table['dataKey'] ?? $table['name'];
            $tableData = $data[$dataKey] ?? [];
            $tables[] = [
                'heading' => $table['heading'],
                'columns' => $table['columns'],
                'rows' => $tableData,
            ];

            if (count($tableData) > $totalRowCount) {
                $totalRowCount = count($tableData);
            }
        }

        $reportTitle = "{$report->getName()} - {$store->name}";
        $subject = "{$reportTitle} - {$reportDate->format('M j, Y')}";

        $mailable = (new DynamicReportMail(
            reportTitle: $reportTitle,
            description: $report->getDescription(),
            content: ['tables' => $tables],
            rowCount: $totalRowCount,
            generatedAt: $reportDate
        ))->withSubject($subject);

        // Set from address using store settings
        $fromAddress = $store->email_from_address ?: config('mail.from.address');
        $fromName = $store->email_from_name ?: config('mail.from.name', $store->name);
        $mailable->from($fromAddress, $fromName);

        if ($store->email_reply_to_address) {
            $mailable->replyTo($store->email_reply_to_address);
        }

        // Send to all recipients
        foreach ($scheduledReport->recipients as $recipient) {
            Mail::to($recipient)->send($mailable);
        }

        // Update last sent timestamp
        $scheduledReport->update([
            'last_sent_at' => now(),
            'last_error' => null,
        ]);
    }
}
