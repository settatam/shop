<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\NotificationSubscription;
use App\Models\Store;
use App\Services\Notifications\DailyBuyReportNotification;
use App\Services\Notifications\DailyMemoReportNotification;
use App\Services\Notifications\DailyRepairReportNotification;
use App\Services\Notifications\DailySalesReportNotification;
use App\Services\Notifications\ItemsNotReviewedNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendDailyReports extends Command
{
    protected $signature = 'reports:send-daily
        {--store= : Store ID (defaults to all stores with report subscriptions)}
        {--type= : buy|sales|items_not_reviewed|memo|repair|all (defaults to all)}
        {--date= : Report date in Y-m-d format (defaults to yesterday)}
        {--dry-run : Show what would be sent without actually sending}';

    protected $description = 'Send daily reports via the notifications system';

    /**
     * Map of report type to Activity constant.
     *
     * @var array<string, string>
     */
    protected array $typeActivityMap = [
        'buy' => Activity::REPORTS_DAILY_BUY,
        'sales' => Activity::REPORTS_DAILY_SALES,
        'items_not_reviewed' => Activity::REPORTS_ITEMS_NOT_REVIEWED,
        'memo' => Activity::REPORTS_DAILY_MEMO,
        'repair' => Activity::REPORTS_DAILY_REPAIR,
    ];

    public function handle(): int
    {
        $reportDate = $this->getReportDate();
        $types = $this->getReportTypes();
        $dryRun = $this->option('dry-run');

        $this->info("Sending daily reports for {$reportDate->format('Y-m-d')}");

        if ($dryRun) {
            $this->warn('DRY RUN - No emails will be sent');
        }

        $stores = $this->getStores($types);

        if ($stores->isEmpty()) {
            $this->warn('No stores found with enabled report subscriptions.');

            return self::SUCCESS;
        }

        $totalSent = 0;

        foreach ($stores as $store) {
            $store->load('owner');
            $this->newLine();
            $this->info("Processing store: {$store->name} (ID: {$store->id})");

            foreach ($types as $type) {
                $activity = $this->typeActivityMap[$type] ?? null;

                if (! $activity) {
                    $this->error("  [{$type}] Unknown report type, skipping");

                    continue;
                }

                $hasSubscription = NotificationSubscription::where('store_id', $store->id)
                    ->where('activity', $activity)
                    ->where('is_enabled', true)
                    ->whereHas('template', fn ($q) => $q->where('is_enabled', true))
                    ->exists();

                if (! $hasSubscription) {
                    $this->line("  [{$type}] No enabled subscription, skipping");

                    continue;
                }

                if ($dryRun) {
                    $this->line("  [{$type}] Would send report to subscribers");

                    continue;
                }

                try {
                    $this->sendReport($type, $store, $reportDate);
                    $totalSent++;
                    $this->line("  [{$type}] Sent successfully");
                } catch (\Exception $e) {
                    $this->error("  [{$type}] Failed: {$e->getMessage()}");
                }
            }
        }

        $this->newLine();
        $this->info("Done. Reports sent: {$totalSent}");

        return self::SUCCESS;
    }

    protected function sendReport(string $type, Store $store, Carbon $reportDate): void
    {
        $notification = match ($type) {
            'buy' => new DailyBuyReportNotification,
            'sales' => new DailySalesReportNotification,
            'items_not_reviewed' => new ItemsNotReviewedNotification,
            'memo' => new DailyMemoReportNotification,
            'repair' => new DailyRepairReportNotification,
        };

        $notification->send($store, $reportDate);
    }

    protected function getReportDate(): Carbon
    {
        $dateOption = $this->option('date');

        if ($dateOption) {
            return Carbon::parse($dateOption, 'America/New_York')->startOfDay();
        }

        return Carbon::yesterday('America/New_York')->startOfDay();
    }

    protected function getReportTypes(): array
    {
        $typeOption = $this->option('type');

        if (! $typeOption || $typeOption === 'all') {
            return array_keys($this->typeActivityMap);
        }

        return [trim($typeOption)];
    }

    /**
     * Get stores that have enabled report subscriptions.
     *
     * @param  array<string>  $types
     * @return \Illuminate\Database\Eloquent\Collection<int, Store>
     */
    protected function getStores(array $types): \Illuminate\Database\Eloquent\Collection
    {
        $storeOption = $this->option('store');

        if ($storeOption) {
            return Store::whereIn('id', explode(',', $storeOption))->get();
        }

        $activities = collect($types)
            ->map(fn ($t) => $this->typeActivityMap[$t] ?? null)
            ->filter()
            ->toArray();

        $storeIds = NotificationSubscription::whereIn('activity', $activities)
            ->where('is_enabled', true)
            ->pluck('store_id')
            ->unique();

        return Store::whereIn('id', $storeIds)->get();
    }
}
