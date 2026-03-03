<?php

namespace App\Console\Commands;

use App\Mail\DynamicReportMail;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class EmailMonthlyBuysReport extends Command
{
    protected $signature = 'reports:email-monthly-buys
                            {email : Email address to send the report to}
                            {--month= : Month (1-12, defaults to previous month)}
                            {--year= : Year (defaults to current year)}
                            {--stores=* : Legacy store IDs to include (e.g. --stores=43 --stores=44)}
                            {--dry-run : Show the report without sending}';

    protected $description = 'Email a monthly buys report from the legacy transactions_warehouse table';

    public function handle(): int
    {
        $email = $this->argument('email');
        $now = Carbon::now();

        $month = (int) ($this->option('month') ?? $now->copy()->subMonth()->month);
        $year = (int) ($this->option('year') ?? ($this->option('month') ? $now->year : $now->copy()->subMonth()->year));
        $storeIds = $this->option('stores');
        $dryRun = $this->option('dry-run');

        $monthStart = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $monthLabel = $monthStart->format('F Y');

        // Resolve store names for display
        $storeFilter = '';
        if (! empty($storeIds)) {
            $stores = DB::connection('legacy')
                ->table('transactions_warehouse')
                ->whereIn('store_id', $storeIds)
                ->distinct()
                ->pluck('store', 'store_id');

            $storeFilter = $stores->map(fn ($name, $id) => "{$name} ({$id})")->implode(', ');
            $this->info("Stores: {$storeFilter}");
        } else {
            $this->info('Stores: All');
        }

        $this->info("Period: {$monthLabel}");
        $this->info("Recipient: {$email}");

        // Query legacy data
        $query = DB::connection('legacy')
            ->table('transactions_warehouse')
            ->whereNotNull('payment_date_time')
            ->whereBetween('payment_date_time', [$monthStart, $monthEnd])
            ->whereNull('deleted_at')
            ->orderBy('payment_date_time', 'desc');

        if (! empty($storeIds)) {
            $query->whereIn('store_id', $storeIds);
        }

        $rows = $query->select([
            'transaction_id',
            'payment_date_time',
            'customer_name',
            'customer_first_name',
            'customer_last_name',
            'estimated_value',
            'bought',
            'final_offer',
            'profit',
            'profit_percent',
            'store_id',
            'store',
        ])->get();

        $this->info("Found {$rows->count()} transactions.");

        if ($rows->isEmpty()) {
            $this->warn('No buys found for this period. No email sent.');

            return self::SUCCESS;
        }

        // Format the data
        $formattedRows = $rows->map(function ($row) {
            $customerName = $row->customer_name
                ?: trim(($row->customer_first_name ?? '').' '.($row->customer_last_name ?? ''))
                ?: '-';
            $buyPrice = (float) ($row->final_offer ?? $row->bought ?? 0);
            $estimatedValue = (float) ($row->estimated_value ?? 0);
            $profit = (float) ($row->profit ?? ($estimatedValue - $buyPrice));
            $profitPercent = (float) ($row->profit_percent ?? ($buyPrice > 0 ? ($profit / $buyPrice) * 100 : 0));

            return [
                'transaction_id' => $row->transaction_id,
                'payment_date' => Carbon::parse($row->payment_date_time)->format('M d, Y'),
                'store' => $row->store ?? $row->store_id,
                'customer' => $customerName,
                'estimated_value' => $estimatedValue,
                'buy_price' => $buyPrice,
                'profit' => $profit,
                'profit_percent' => $profitPercent,
            ];
        });

        // Calculate totals
        $totalEstimatedValue = $formattedRows->sum('estimated_value');
        $totalBuyPrice = $formattedRows->sum('buy_price');
        $totalProfit = $formattedRows->sum('profit');
        $avgProfitPercent = $totalBuyPrice > 0 ? ($totalProfit / $totalBuyPrice) * 100 : 0;

        $headers = ['Transaction ID', 'Payment Date', 'Store', 'Customer', 'Estimated Value', 'Buy Price', 'Profit', 'Profit %'];

        $formatRow = fn ($row) => [
            $row['transaction_id'] ?? 'TOTALS',
            $row['payment_date'] ?? '',
            $row['store'] ?? '',
            $row['customer'] ?? '',
            '$'.number_format($row['estimated_value'], 2),
            '$'.number_format($row['buy_price'], 2),
            '$'.number_format($row['profit'], 2),
            number_format($row['profit_percent'], 1).'%',
        ];

        $totals = [
            'transaction_id' => null,
            'payment_date' => '',
            'store' => '',
            'customer' => "{$rows->count()} transactions",
            'estimated_value' => $totalEstimatedValue,
            'buy_price' => $totalBuyPrice,
            'profit' => $totalProfit,
            'profit_percent' => $avgProfitPercent,
        ];

        if ($dryRun) {
            $this->table(
                $headers,
                $formattedRows->map($formatRow)->push($formatRow($totals))->toArray()
            );
            $this->info('DRY RUN - no email sent.');

            return self::SUCCESS;
        }

        // Build and send the email
        $description = $storeFilter
            ? "Monthly Buys Report - {$monthLabel} ({$storeFilter})"
            : "Monthly Buys Report - {$monthLabel}";

        $displayRows = $formattedRows->map($formatRow)->toArray();
        $displayRows[] = $formatRow($totals);

        $csvRows = $formattedRows->map($formatRow)->toArray();
        $csvRows[] = $formatRow($totals);

        $mailable = new DynamicReportMail(
            reportTitle: "Monthly Buys Report - {$monthLabel}",
            description: $description,
            content: [
                'headers' => $headers,
                'rows' => $displayRows,
            ],
            rowCount: $rows->count(),
            generatedAt: Carbon::now(),
        );

        $csvFilename = 'monthly-buys-report-'.$monthStart->format('Y-m').'.csv';
        $mailable->attachCsv($headers, $csvRows, $csvFilename);

        Mail::to($email)->send($mailable);

        $this->info("Report sent to {$email}.");

        return self::SUCCESS;
    }
}
