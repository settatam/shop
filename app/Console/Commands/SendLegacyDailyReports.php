<?php

namespace App\Console\Commands;

use App\Models\NotificationSubscription;
use App\Models\NotificationTemplate;
use App\Models\Store;
use App\Models\TransactionWarehouse;
use App\Services\Notifications\NotificationManager;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendLegacyDailyReports extends Command
{
    protected $signature = 'reports:send-legacy-daily
        {--store= : Comma-separated list of new system store IDs (defaults to all configured)}
        {--type= : Report type to send (daily_sales, daily_buy, or all)}
        {--date= : Date for the report (Y-m-d format, defaults to yesterday)}
        {--dry-run : Show what would be sent without actually sending}
        {--test-email= : Send to a specific email address for testing}
        {--show-data : Output the data structure that will be passed to templates (for AI template generation)}';

    protected $description = 'Send daily reports for legacy stores using notification templates';

    protected array $reportTypeMapping = [
        'daily_sales' => 'daily-sales-report',
        'daily_buy' => 'daily-buy-report',
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

        $storeIds = $this->getStoreIds($storeMapping);

        if (empty($storeIds)) {
            $this->error('No valid store IDs specified.');

            return self::FAILURE;
        }

        $reportTypes = $this->getReportTypes();
        $reportDate = $this->getReportDate();
        $dryRun = $this->option('dry-run');
        $testEmail = $this->option('test-email');
        $showData = $this->option('show-data');

        // If --show-data, just output the data structure and exit
        if ($showData) {
            return $this->showDataStructure($storeIds, $reportTypes, $reportDate);
        }

        $this->info("Sending legacy daily reports for {$reportDate->format('Y-m-d')}");

        if ($dryRun) {
            $this->warn('DRY RUN - No emails will be sent');
        }

        if ($testEmail) {
            $this->warn("TEST MODE - Sending to: {$testEmail}");
        }

        $totalSent = 0;

        foreach ($storeIds as $storeId) {
            $this->newLine();
            $this->info("Processing store {$storeId}...");

            $store = Store::find($storeId);

            if (! $store) {
                $this->warn("  Store {$storeId} not found, skipping");

                continue;
            }

            $store->load('address', 'owner');

            foreach ($reportTypes as $reportType => $templateSlug) {
                // Look for subscription with this activity in the new system
                $subscription = NotificationSubscription::where('store_id', $storeId)
                    ->where('activity', 'reports.'.$reportType)
                    ->where('is_enabled', true)
                    ->with('template')
                    ->first();

                // If no subscription exists, try to find just the template
                $template = $subscription?->template ?? NotificationTemplate::where('store_id', $storeId)
                    ->where('slug', $templateSlug)
                    ->where('is_enabled', true)
                    ->first();

                if (! $template) {
                    $this->line("  [{$reportType}] No template found (slug: {$templateSlug}), skipping");
                    $this->line("    Tip: Create a template with slug '{$templateSlug}' or run: php artisan reports:setup-templates --store={$storeId}");

                    continue;
                }

                // Get recipients
                $recipients = $this->getRecipients($subscription, $testEmail, $store);

                if (empty($recipients)) {
                    $this->line("  [{$reportType}] No recipients configured, skipping");

                    continue;
                }

                $this->line("  [{$reportType}] Recipients: ".implode(', ', $recipients));

                // Generate report data
                $reportData = $this->generateReportData($store, $reportType, $reportDate);

                if ($dryRun) {
                    $this->line("  [{$reportType}] Would send report with ".count($reportData['data']).' rows to '.count($recipients).' recipients');

                    continue;
                }

                // Send the report
                $this->sendReport($store, $template, $reportData, $recipients);
                $totalSent++;
                $this->line("  [{$reportType}] Sent successfully");
            }
        }

        $this->newLine();
        $this->info("Done. Reports sent: {$totalSent}");

        return self::SUCCESS;
    }

    protected function getStoreIds(array $storeMapping): array
    {
        $storeOption = $this->option('store');

        if ($storeOption) {
            return array_map('intval', explode(',', $storeOption));
        }

        // Return the new system store IDs from the mapping
        return array_values($storeMapping);
    }

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
        $timezone = config('legacy-sync.schedule.timezone', 'America/New_York');

        if ($dateOption) {
            return Carbon::parse($dateOption, $timezone)->startOfDay();
        }

        return Carbon::yesterday($timezone)->startOfDay();
    }

    protected function getRecipients(?NotificationSubscription $subscription, ?string $testEmail, Store $store): array
    {
        if ($testEmail) {
            return [$testEmail];
        }

        if ($subscription && ! empty($subscription->recipients)) {
            $emails = [];
            foreach ($subscription->recipients as $recipient) {
                if (is_array($recipient)) {
                    $type = $recipient['type'] ?? null;
                    $value = $recipient['value'] ?? null;

                    if ($type === 'custom' && $value) {
                        $customEmails = array_map('trim', explode(',', $value));
                        $emails = array_merge($emails, $customEmails);
                    } elseif ($type === 'owner' && $store->owner?->email) {
                        $emails[] = $store->owner->email;
                    }
                } elseif (is_string($recipient) && filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                    $emails[] = $recipient;
                }
            }

            return array_unique(array_filter($emails));
        }

        // Fallback to store owner
        if ($store->owner?->email) {
            return [$store->owner->email];
        }

        return [];
    }

    protected function generateReportData(Store $store, string $reportType, Carbon $reportDate): array
    {
        $fromLabel = $reportDate->format('m-d-Y');

        $data = [
            'date' => 'REB - Daily '.ucfirst(str_replace('_', ' ', $reportType))." Report for {$fromLabel}",
            'store' => $store,
        ];

        if ($reportType === 'daily_sales') {
            $data = array_merge($data, $this->generateSalesReportData($store->id, $reportDate));
        } elseif ($reportType === 'daily_buy') {
            $data = array_merge($data, $this->generateBuyReportData($store->id, $reportDate));
        }

        return $data;
    }

    protected function generateSalesReportData(int $storeId, Carbon $reportDate): array
    {
        // Daily data - sales for the specific date
        $dailyData = DB::table('orders')
            ->where('store_id', $storeId)
            ->whereDate('created_at', $reportDate)
            ->whereNull('deleted_at')
            ->select(
                DB::raw('COUNT(*) as sales_count'),
                DB::raw('SUM(total) as total_sales'),
                DB::raw('SUM(subtotal) as subtotal'),
            )
            ->first();

        // Month to date
        $startOfMonth = $reportDate->copy()->startOfMonth();
        $monthlyData = DB::table('orders')
            ->where('store_id', $storeId)
            ->whereBetween('created_at', [$startOfMonth, $reportDate->copy()->endOfDay()])
            ->whereNull('deleted_at')
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as sales_count'),
                DB::raw('SUM(total) as total_sales'),
            )
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();

        // Month over month - compare current month to previous month
        $monthOverMonth = [
            [
                $reportDate->format('F Y'),
                $dailyData->sales_count ?? 0,
                0, // items sold
                '$'.number_format($dailyData->total_sales ?? 0, 2),
            ],
        ];

        $headings = ['Date', 'Order #', 'Customer', 'Total', 'Status'];

        // Get detailed daily orders for the report
        $orders = DB::table('orders')
            ->where('store_id', $storeId)
            ->whereDate('created_at', $reportDate)
            ->whereNull('deleted_at')
            ->get();

        $data = $orders->map(fn ($order) => [
            Carbon::parse($order->created_at)->format('m-d-Y'),
            $order->order_number ?? $order->id,
            $order->customer_name ?? 'N/A',
            '$'.number_format($order->total ?? 0, 2),
            $order->status ?? 'N/A',
        ])->toArray();

        return [
            'headings' => $headings,
            'data' => $data,
            'monthlyData' => $monthlyData->map(fn ($row) => [
                Carbon::parse($row->date)->format('m-d-Y'),
                $row->sales_count,
                0,
                '$'.number_format($row->total_sales ?? 0, 2),
            ])->toArray(),
            'monthOverMonth' => $monthOverMonth,
            'monthOverMonthHeading' => ['Month', 'Sales #', 'Items Sold', 'Total Sales'],
            'monthly_headings' => ['Month', 'Sales #', 'Items Sold', 'Total Sales'],
        ];
    }

    protected function generateBuyReportData(int $storeId, Carbon $reportDate): array
    {
        $headings = ['Date', 'Transaction #', 'Customer', 'Bought', 'Profit', 'Payment Type'];

        $transactions = TransactionWarehouse::where('store_id', $storeId)
            ->whereDate('payment_date_time', $reportDate)
            ->get();

        $data = $transactions->map(fn ($txn) => [
            $txn->payment_date_time?->format('m-d-Y') ?? 'N/A',
            $txn->transaction_id ?? $txn->id,
            $txn->customer_name ?? 'N/A',
            '$'.number_format($txn->bought ?? 0, 2),
            '$'.number_format($txn->profit ?? 0, 2),
            $txn->payment_type ?? 'N/A',
        ])->toArray();

        // Calculate totals
        $totalBought = $transactions->sum('bought');
        $totalProfit = $transactions->sum('profit');

        // Month to date
        $startOfMonth = $reportDate->copy()->startOfMonth();
        $monthlyTransactions = TransactionWarehouse::where('store_id', $storeId)
            ->whereBetween('payment_date_time', [$startOfMonth, $reportDate->copy()->endOfDay()])
            ->selectRaw('DATE(payment_date_time) as date, COUNT(*) as count, SUM(bought) as total_bought, SUM(profit) as total_profit')
            ->groupBy(DB::raw('DATE(payment_date_time)'))
            ->orderBy('date')
            ->get();

        return [
            'headings' => $headings,
            'data' => $data,
            'monthlyData' => $monthlyTransactions->map(fn ($row) => [
                Carbon::parse($row->date)->format('m-d-Y'),
                $row->count,
                '$'.number_format($row->total_bought ?? 0, 2),
                '$'.number_format($row->total_profit ?? 0, 2),
            ])->toArray(),
            'monthOverMonth' => [
                [$reportDate->format('F Y'), count($data), '$'.number_format($totalBought, 2), '$'.number_format($totalProfit, 2)],
            ],
            'monthOverMonthHeading' => ['Month', 'Transactions', 'Total Bought', 'Total Profit'],
            'monthly_headings' => ['Date', 'Count', 'Total Bought', 'Total Profit'],
        ];
    }

    protected function sendReport(Store $store, NotificationTemplate $template, array $reportData, array $recipients): void
    {
        $notificationManager = new NotificationManager($store);

        foreach ($recipients as $recipient) {
            $notificationManager->sendTemplate($template, $recipient, $reportData);
        }
    }

    /**
     * Show the data structure that will be passed to templates.
     * Useful for AI template generation - pass this as sample_data.
     */
    protected function showDataStructure(array $storeIds, array $reportTypes, Carbon $reportDate): int
    {
        $storeId = $storeIds[0] ?? null;
        $store = $storeId ? Store::find($storeId) : null;

        if (! $store) {
            $this->error('No valid store found. Use --store=ID');

            return self::FAILURE;
        }

        $store->load('address', 'owner');

        $this->info('Data Structure for Report Templates');
        $this->info('====================================');
        $this->newLine();
        $this->line("Store: {$store->name} (ID: {$store->id})");
        $this->line("Report Date: {$reportDate->format('Y-m-d')}");
        $this->newLine();

        foreach ($reportTypes as $reportType => $templateSlug) {
            $this->info("Report Type: {$reportType}");
            $this->line(str_repeat('-', 50));

            $reportData = $this->generateReportData($store, $reportType, $reportDate);

            // Convert store object to array for display
            $displayData = $reportData;
            $displayData['store'] = [
                'id' => $store->id,
                'name' => $store->name,
                'email' => $store->email,
                'phone' => $store->phone,
                'address' => $store->address ? [
                    'street' => $store->address->street,
                    'city' => $store->address->city,
                    'state' => $store->address->state,
                    'zip' => $store->address->zip,
                ] : null,
            ];

            $this->line(json_encode($displayData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->newLine();

            $this->comment('Available Variables:');
            $this->line('  - date: string (e.g., "REB - Daily Sales Report for 01-15-2025")');
            $this->line('  - store: object with id, name, email, phone, address');
            $this->line('  - headings: array of column header strings');
            $this->line('  - data: array of arrays (each inner array is a row)');
            $this->line('  - monthlyData: array of arrays (month-to-date rows)');
            $this->line('  - monthly_headings: array of column headers for monthly data');
            $this->line('  - monthOverMonth: array of arrays (comparison rows)');
            $this->line('  - monthOverMonthHeading: array of column headers');
            $this->newLine();

            $this->comment('Twig Template Example:');
            $this->line('  {% for row in data %}');
            $this->line('    <tr>{% for cell in row %}<td>{{ cell }}</td>{% endfor %}</tr>');
            $this->line('  {% endfor %}');
            $this->newLine();
        }

        $this->info('To generate a template with AI, use this data as sample_data:');
        $this->line('  POST /api/v1/ai/templates/generate-report');
        $this->line('  {');
        $this->line('    "report_type": "daily_sales",');
        $this->line('    "description": "Your description here",');
        $this->line('    "sample_data": <paste the JSON above>');
        $this->line('  }');

        return self::SUCCESS;
    }
}
