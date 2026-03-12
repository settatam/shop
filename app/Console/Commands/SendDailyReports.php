<?php

namespace App\Console\Commands;

use App\Models\NotificationSubscription;
use App\Models\Store;
use App\Services\Notifications\DailyBuyReportNotification;
use App\Services\Notifications\DailySalesReportNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendDailyReports extends Command
{
    protected $signature = 'reports:send-daily
        {--store= : Store ID (defaults to all stores with report subscriptions)}
        {--type= : buy|sales|all (defaults to all)}
        {--date= : Report date in Y-m-d format (defaults to yesterday)}
        {--dry-run : Show what would be sent without actually sending}';

    protected $description = 'Send daily buy and/or sales reports via the notifications system';

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
                $activity = $type === 'buy' ? 'reports.daily_buy' : 'reports.daily_sales';

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
                    if ($type === 'buy') {
                        $notification = new DailyBuyReportNotification;
                        $notification->send($store, $reportDate);
                    } else {
                        $notification = new DailySalesReportNotification;
                        $notification->send($store, $reportDate);
                    }

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
            return ['buy', 'sales'];
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

        $activities = collect($types)->map(fn ($t) => $t === 'buy' ? 'reports.daily_buy' : 'reports.daily_sales')->toArray();

        $storeIds = NotificationSubscription::whereIn('activity', $activities)
            ->where('is_enabled', true)
            ->pluck('store_id')
            ->unique();

        return Store::whereIn('id', $storeIds)->get();
    }
}
