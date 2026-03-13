<?php

namespace App\Services\Reports\Email;

use App\Models\Repair;
use App\Services\Reports\AbstractReport;
use App\Services\Reports\ReportStructure;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Daily Repair Report.
 *
 * Two tables:
 * 1. Active Repairs - repairs in open statuses (pending, sent_to_vendor, received_by_vendor)
 * 2. Daily Repair Activity - repairs created or updated on the report date
 */
class DailyRepairReport extends AbstractReport
{
    protected string $baseUrl;

    /** @var array<string> */
    protected array $openStatuses = [
        Repair::STATUS_PENDING,
        Repair::STATUS_SENT_TO_VENDOR,
    ];

    public function __construct($store, ?Carbon $reportDate = null, ?string $baseUrl = null)
    {
        parent::__construct($store, $reportDate);
        $this->baseUrl = $baseUrl ?? config('app.url');
    }

    public function getType(): string
    {
        return 'daily_repair';
    }

    public function getName(): string
    {
        return 'Daily Repair Report';
    }

    public function getSlug(): string
    {
        return 'daily-repair-report';
    }

    protected function defineStructure(): ReportStructure
    {
        return $this->structure()
            ->setTitle('{{ date }}')
            ->addTable(
                name: 'active_repairs',
                heading: 'Active Repairs',
                columns: [
                    $this->linkColumn('repair_number', 'Repair #', '/repairs/{id}'),
                    $this->textColumn('customer', 'Customer'),
                    $this->textColumn('vendor', 'Vendor'),
                    $this->badgeColumn('status', 'Status', [
                        'pending' => 'warning',
                        'sent_to_vendor' => 'info',
                    ]),
                    $this->currencyColumn('total', 'Total'),
                    $this->numberColumn('repair_days', 'Repair Days'),
                ],
                dataKey: 'active_repairs'
            )
            ->addTable(
                name: 'daily_activity',
                heading: 'Daily Repair Activity',
                columns: [
                    $this->linkColumn('repair_number', 'Repair #', '/repairs/{id}'),
                    $this->textColumn('customer', 'Customer'),
                    $this->textColumn('vendor', 'Vendor'),
                    $this->badgeColumn('status', 'Status', [
                        'pending' => 'warning',
                        'sent_to_vendor' => 'info',
                        'received_by_vendor' => 'info',
                        'completed' => 'success',
                        'payment_received' => 'success',
                        'refunded' => 'danger',
                        'cancelled' => 'danger',
                        'archived' => 'secondary',
                    ]),
                    $this->textColumn('action', 'Action'),
                    $this->currencyColumn('total', 'Total'),
                ],
                dataKey: 'daily_activity'
            )
            ->setMetadata([
                'report_type' => 'daily_repair',
                'store_id' => $this->store->id,
            ]);
    }

    public function getData(): array
    {
        return [
            'date' => $this->getTitleWithDate('Daily Repair Report'),
            'store' => $this->store,
            'active_repairs' => $this->getActiveRepairs(),
            'daily_activity' => $this->getDailyActivity(),
        ];
    }

    protected function getActiveRepairs(): array
    {
        $repairs = Repair::query()
            ->where('store_id', $this->store->id)
            ->whereIn('status', $this->openStatuses)
            ->with(['customer', 'vendor'])
            ->orderBy('created_at', 'asc')
            ->get();

        $rows = $repairs->map(function (Repair $repair) {
            $repairDays = $repair->date_sent_to_vendor
                ? (int) Carbon::parse($repair->date_sent_to_vendor)->diffInDays($this->reportDate)
                : (int) $repair->created_at->diffInDays($this->reportDate);

            return [
                'id' => $repair->id,
                'repair_number' => $this->formatLink(
                    $repair->repair_number,
                    "{$this->baseUrl}/repairs/{$repair->id}"
                ),
                'customer' => $repair->customer?->full_name ?? '-',
                'vendor' => $repair->vendor?->name ?? '-',
                'status' => $this->formatBadge(
                    Str::title(str_replace('_', ' ', $repair->status)),
                    $this->getRepairStatusVariant($repair->status)
                ),
                'total' => $this->formatCurrency($repair->total),
                'repair_days' => $repairDays,
            ];
        })->toArray();

        if (count($rows) > 0) {
            $totalAmount = $repairs->sum('total');

            $rows[] = [
                'id' => null,
                'repair_number' => ['data' => 'Totals', 'href' => ''],
                'customer' => $repairs->count().' repairs',
                'vendor' => '',
                'status' => ['data' => '', 'variant' => 'secondary'],
                'total' => $this->formatCurrency($totalAmount),
                'repair_days' => '',
                '_is_total' => true,
            ];
        }

        return $rows;
    }

    protected function getDailyActivity(): array
    {
        $startOfDay = $this->reportDate->copy()->startOfDay();
        $endOfDay = $this->reportDate->copy()->endOfDay();

        $repairs = Repair::query()
            ->where('store_id', $this->store->id)
            ->where(function ($query) use ($startOfDay, $endOfDay) {
                $query->whereBetween('created_at', [$startOfDay, $endOfDay])
                    ->orWhereBetween('updated_at', [$startOfDay, $endOfDay]);
            })
            ->with(['customer', 'vendor'])
            ->orderBy('updated_at', 'desc')
            ->get();

        $rows = $repairs->map(function (Repair $repair) use ($startOfDay, $endOfDay) {
            $action = $repair->created_at->between($startOfDay, $endOfDay) ? 'Created' : 'Updated';

            return [
                'id' => $repair->id,
                'repair_number' => $this->formatLink(
                    $repair->repair_number,
                    "{$this->baseUrl}/repairs/{$repair->id}"
                ),
                'customer' => $repair->customer?->full_name ?? '-',
                'vendor' => $repair->vendor?->name ?? '-',
                'status' => $this->formatBadge(
                    Str::title(str_replace('_', ' ', $repair->status)),
                    $this->getRepairStatusVariant($repair->status)
                ),
                'action' => $action,
                'total' => $this->formatCurrency($repair->total),
            ];
        })->toArray();

        if (count($rows) > 0) {
            $totalAmount = $repairs->sum('total');

            $rows[] = [
                'id' => null,
                'repair_number' => ['data' => 'Totals', 'href' => ''],
                'customer' => $repairs->count().' repairs',
                'vendor' => '',
                'status' => ['data' => '', 'variant' => 'secondary'],
                'action' => '',
                'total' => $this->formatCurrency($totalAmount),
                '_is_total' => true,
            ];
        }

        return $rows;
    }

    protected function getRepairStatusVariant(string $status): string
    {
        return match ($status) {
            Repair::STATUS_PENDING => 'warning',
            Repair::STATUS_SENT_TO_VENDOR, Repair::STATUS_RECEIVED_BY_VENDOR => 'info',
            Repair::STATUS_COMPLETED, Repair::STATUS_PAYMENT_RECEIVED => 'success',
            Repair::STATUS_REFUNDED, Repair::STATUS_CANCELLED => 'danger',
            default => 'secondary',
        };
    }
}
