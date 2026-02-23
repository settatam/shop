<?php

namespace App\Console\Commands;

use App\Mail\DynamicReportMail;
use App\Models\TransactionWarehouse;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SendLegacyDailyReports extends Command
{
    protected $signature = 'reports:send-legacy-daily
        {--store= : Comma-separated list of legacy store IDs (defaults to all configured)}
        {--type= : Report type to send (daily_sales, daily_buy, daily_memos, daily_repairs, or all)}
        {--date= : Date for the report (Y-m-d format, defaults to yesterday)}
        {--dry-run : Show what would be sent without actually sending}';

    protected $description = 'Send daily reports for legacy stores';

    protected array $reportTypeMapping = [
        'daily_sales' => 'Daily Sales Report',
        'daily_buy' => 'Daily Buy Report',
        'daily_memos' => 'Daily Memos Report',
        'daily_repairs' => 'Daily Repairs Report',
    ];

    public function handle(): int
    {
        if (! config('legacy-sync.enabled') || ! config('legacy-sync.reports.enabled')) {
            $this->error('Legacy reports are disabled. Set LEGACY_SYNC_ENABLED=true and LEGACY_REPORTS_ENABLED=true.');

            return self::FAILURE;
        }

        $storeMapping = TransactionWarehouse::getStoreMapping();

        if (empty($storeMapping)) {
            $this->error('No store mappings configured.');

            return self::FAILURE;
        }

        $legacyStoreIds = $this->getLegacyStoreIds($storeMapping);

        if (empty($legacyStoreIds)) {
            $this->error('No valid store IDs specified.');

            return self::FAILURE;
        }

        $reportTypes = $this->getReportTypes();
        $reportDate = $this->getReportDate();
        $dryRun = $this->option('dry-run');

        $this->info("Sending legacy daily reports for {$reportDate->format('Y-m-d')}");

        if ($dryRun) {
            $this->warn('DRY RUN - No emails will be sent');
        }

        $connection = config('legacy-sync.connection');
        $totalSent = 0;

        foreach ($legacyStoreIds as $legacyStoreId) {
            $this->newLine();
            $this->info("Processing legacy store {$legacyStoreId}...");

            foreach ($reportTypes as $reportType => $reportName) {
                $recipients = $this->getReportRecipients($connection, $legacyStoreId, $reportName);

                if (empty($recipients)) {
                    $this->line("  [{$reportType}] No recipients configured, skipping");

                    continue;
                }

                $this->line("  [{$reportType}] Recipients: ".implode(', ', $recipients));

                $reportData = $this->generateReportData($legacyStoreId, $reportType, $reportDate);

                if ($dryRun) {
                    $this->line("  [{$reportType}] Would send {$reportData['rowCount']} rows to ".count($recipients).' recipients');

                    continue;
                }

                $this->sendReport($reportName, $reportData, $recipients, $reportDate);
                $totalSent++;
                $this->line("  [{$reportType}] Sent successfully");
            }
        }

        $this->newLine();
        $this->info("Done. Reports sent: {$totalSent}");

        return self::SUCCESS;
    }

    /**
     * @return array<int>
     */
    protected function getLegacyStoreIds(array $storeMapping): array
    {
        $storeOption = $this->option('store');

        if ($storeOption) {
            $requestedIds = array_map('intval', explode(',', $storeOption));

            return array_filter($requestedIds, fn ($id) => isset($storeMapping[$id]));
        }

        return array_keys($storeMapping);
    }

    /**
     * @return array<string, string>
     */
    protected function getReportTypes(): array
    {
        $typeOption = $this->option('type');

        if ($typeOption && $typeOption !== 'all') {
            $types = explode(',', $typeOption);
            $filtered = [];
            foreach ($types as $type) {
                $type = trim($type);
                if (isset($this->reportTypeMapping[$type])) {
                    $filtered[$type] = $this->reportTypeMapping[$type];
                }
            }

            return $filtered;
        }

        return $this->reportTypeMapping;
    }

    protected function getReportDate(): Carbon
    {
        $dateOption = $this->option('date');

        if ($dateOption) {
            return Carbon::parse($dateOption)->startOfDay();
        }

        return Carbon::yesterday()->startOfDay();
    }

    /**
     * @return array<string>
     */
    protected function getReportRecipients(string $connection, int $legacyStoreId, string $reportName): array
    {
        $notification = DB::connection($connection)
            ->table('store_notifications')
            ->where('store_id', $legacyStoreId)
            ->where('name', $reportName)
            ->whereNull('deleted_at')
            ->where('is_deactivated', false)
            ->first();

        if (! $notification || empty($notification->send_to)) {
            return [];
        }

        $emails = array_filter(array_map('trim', explode(',', $notification->send_to)));

        return array_filter($emails, fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL));
    }

    /**
     * @return array{headers: array<string>, rows: array<array<mixed>>, rowCount: int}
     */
    protected function generateReportData(int $legacyStoreId, string $reportType, Carbon $reportDate): array
    {
        $newStoreId = TransactionWarehouse::mapLegacyStoreId($legacyStoreId);

        return match ($reportType) {
            'daily_sales' => $this->generateSalesReport($newStoreId, $reportDate),
            'daily_buy' => $this->generateBuyReport($newStoreId, $reportDate),
            'daily_memos' => $this->generateMemosReport($newStoreId, $reportDate),
            'daily_repairs' => $this->generateRepairsReport($newStoreId, $reportDate),
            default => ['headers' => [], 'rows' => [], 'rowCount' => 0],
        };
    }

    /**
     * @return array{headers: array<string>, rows: array<array<mixed>>, rowCount: int}
     */
    protected function generateSalesReport(int $storeId, Carbon $reportDate): array
    {
        $orders = DB::table('orders')
            ->where('store_id', $storeId)
            ->whereDate('created_at', $reportDate)
            ->whereNull('deleted_at')
            ->get();

        $headers = ['Order #', 'Customer', 'Total', 'Status', 'Created At'];
        $rows = $orders->map(fn ($order) => [
            $order->order_number ?? $order->id,
            $order->customer_name ?? 'N/A',
            '$'.number_format($order->total ?? 0, 2),
            $order->status ?? 'N/A',
            Carbon::parse($order->created_at)->format('M j, Y g:i A'),
        ])->toArray();

        return [
            'headers' => $headers,
            'rows' => $rows,
            'rowCount' => count($rows),
        ];
    }

    /**
     * @return array{headers: array<string>, rows: array<array<mixed>>, rowCount: int}
     */
    protected function generateBuyReport(int $storeId, Carbon $reportDate): array
    {
        $transactions = TransactionWarehouse::where('store_id', $storeId)
            ->whereDate('payment_date_time', $reportDate)
            ->get();

        $headers = ['Transaction ID', 'Customer', 'Bought', 'Profit', 'Payment Type', 'Paid At'];
        $rows = $transactions->map(fn ($txn) => [
            $txn->transaction_id ?? $txn->id,
            $txn->customer_name ?? 'N/A',
            '$'.number_format($txn->bought ?? 0, 2),
            '$'.number_format($txn->profit ?? 0, 2),
            $txn->payment_type ?? 'N/A',
            $txn->payment_date_time?->format('M j, Y g:i A') ?? 'N/A',
        ])->toArray();

        return [
            'headers' => $headers,
            'rows' => $rows,
            'rowCount' => count($rows),
        ];
    }

    /**
     * @return array{headers: array<string>, rows: array<array<mixed>>, rowCount: int}
     */
    protected function generateMemosReport(int $storeId, Carbon $reportDate): array
    {
        $memos = DB::table('memos')
            ->where('store_id', $storeId)
            ->whereDate('created_at', $reportDate)
            ->whereNull('deleted_at')
            ->get();

        $headers = ['Memo #', 'Customer', 'Total', 'Status', 'Created At'];
        $rows = $memos->map(fn ($memo) => [
            $memo->memo_number ?? $memo->id,
            $memo->customer_name ?? 'N/A',
            '$'.number_format($memo->total ?? 0, 2),
            $memo->status ?? 'N/A',
            Carbon::parse($memo->created_at)->format('M j, Y g:i A'),
        ])->toArray();

        return [
            'headers' => $headers,
            'rows' => $rows,
            'rowCount' => count($rows),
        ];
    }

    /**
     * @return array{headers: array<string>, rows: array<array<mixed>>, rowCount: int}
     */
    protected function generateRepairsReport(int $storeId, Carbon $reportDate): array
    {
        $repairs = DB::table('repairs')
            ->where('store_id', $storeId)
            ->whereDate('created_at', $reportDate)
            ->whereNull('deleted_at')
            ->get();

        $headers = ['Repair #', 'Customer', 'Total', 'Status', 'Created At'];
        $rows = $repairs->map(fn ($repair) => [
            $repair->repair_number ?? $repair->id,
            $repair->customer_name ?? 'N/A',
            '$'.number_format($repair->total ?? 0, 2),
            $repair->status ?? 'N/A',
            Carbon::parse($repair->created_at)->format('M j, Y g:i A'),
        ])->toArray();

        return [
            'headers' => $headers,
            'rows' => $rows,
            'rowCount' => count($rows),
        ];
    }

    /**
     * @param  array<string>  $recipients
     * @param  array{headers: array<string>, rows: array<array<mixed>>, rowCount: int}  $reportData
     */
    protected function sendReport(string $reportName, array $reportData, array $recipients, Carbon $reportDate): void
    {
        $mail = new DynamicReportMail(
            reportTitle: $reportName,
            description: "Report for {$reportDate->format('F j, Y')}",
            content: [
                'headers' => $reportData['headers'],
                'rows' => $reportData['rows'],
            ],
            rowCount: $reportData['rowCount'],
            generatedAt: now()
        );

        if ($reportData['rowCount'] > 0) {
            $mail->attachCsv($reportData['headers'], $reportData['rows']);
        }

        Mail::to($recipients)->send($mail);
    }
}
