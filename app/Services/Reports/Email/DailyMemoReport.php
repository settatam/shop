<?php

namespace App\Services\Reports\Email;

use App\Models\Memo;
use App\Services\Reports\AbstractReport;
use App\Services\Reports\ReportStructure;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Daily Memo Report.
 *
 * Two tables:
 * 1. Active Memos - memos in open statuses (pending, sent_to_vendor, vendor_received)
 * 2. Daily Memo Activity - memos created or updated on the report date
 */
class DailyMemoReport extends AbstractReport
{
    protected string $baseUrl;

    /** @var array<string> */
    protected array $openStatuses = [
        Memo::STATUS_PENDING,
        Memo::STATUS_SENT_TO_VENDOR,
        Memo::STATUS_VENDOR_RECEIVED,
    ];

    public function __construct($store, ?Carbon $reportDate = null, ?string $baseUrl = null)
    {
        parent::__construct($store, $reportDate);
        $this->baseUrl = $baseUrl ?? config('app.url');
    }

    public function getType(): string
    {
        return 'daily_memo';
    }

    public function getName(): string
    {
        return 'Daily Memo Report';
    }

    public function getSlug(): string
    {
        return 'daily-memo-report';
    }

    protected function defineStructure(): ReportStructure
    {
        return $this->structure()
            ->setTitle('{{ date }}')
            ->addTable(
                name: 'active_memos',
                heading: 'Active Memos',
                columns: [
                    $this->linkColumn('memo_number', 'Memo #', '/memos/{id}'),
                    $this->textColumn('vendor', 'Vendor'),
                    $this->badgeColumn('status', 'Status', [
                        'pending' => 'warning',
                        'sent_to_vendor' => 'info',
                        'vendor_received' => 'info',
                    ]),
                    $this->numberColumn('item_count', '# Items'),
                    $this->currencyColumn('total', 'Total'),
                    $this->numberColumn('days_with_vendor', 'Days With Vendor'),
                    $this->numberColumn('tenure', 'Tenure'),
                ],
                dataKey: 'active_memos'
            )
            ->addTable(
                name: 'daily_activity',
                heading: 'Daily Memo Activity',
                columns: [
                    $this->linkColumn('memo_number', 'Memo #', '/memos/{id}'),
                    $this->textColumn('vendor', 'Vendor'),
                    $this->badgeColumn('status', 'Status', [
                        'pending' => 'warning',
                        'sent_to_vendor' => 'info',
                        'vendor_received' => 'info',
                        'vendor_returned' => 'success',
                        'payment_received' => 'success',
                        'cancelled' => 'danger',
                        'archived' => 'secondary',
                    ]),
                    $this->textColumn('action', 'Action'),
                    $this->currencyColumn('total', 'Total'),
                ],
                dataKey: 'daily_activity'
            )
            ->setMetadata([
                'report_type' => 'daily_memo',
                'store_id' => $this->store->id,
            ]);
    }

    public function getData(): array
    {
        return [
            'date' => $this->getTitleWithDate('Daily Memo Report'),
            'store' => $this->store,
            'active_memos' => $this->getActiveMemos(),
            'daily_activity' => $this->getDailyActivity(),
        ];
    }

    protected function getActiveMemos(): array
    {
        $memos = Memo::query()
            ->where('store_id', $this->store->id)
            ->whereIn('status', $this->openStatuses)
            ->with(['vendor', 'items'])
            ->orderBy('created_at', 'asc')
            ->get();

        $rows = $memos->map(function (Memo $memo) {
            $daysWithVendor = 0;
            if ($memo->date_sent_to_vendor) {
                $daysWithVendor = (int) Carbon::parse($memo->date_sent_to_vendor)->diffInDays($this->reportDate);
            }

            return [
                'id' => $memo->id,
                'memo_number' => $this->formatLink(
                    $memo->memo_number,
                    "{$this->baseUrl}/memos/{$memo->id}"
                ),
                'vendor' => $memo->vendor?->name ?? '-',
                'status' => $this->formatBadge(
                    Str::title(str_replace('_', ' ', $memo->status)),
                    $this->getMemoStatusVariant($memo->status)
                ),
                'item_count' => $memo->items->count(),
                'total' => $this->formatCurrency($memo->total),
                'days_with_vendor' => $daysWithVendor,
                'tenure' => $memo->tenure ?? 0,
            ];
        })->toArray();

        if (count($rows) > 0) {
            $totalAmount = $memos->sum('total');
            $totalItems = $memos->sum(fn ($m) => $m->items->count());

            $rows[] = [
                'id' => null,
                'memo_number' => ['data' => 'Totals', 'href' => ''],
                'vendor' => $memos->count().' memos',
                'status' => ['data' => '', 'variant' => 'secondary'],
                'item_count' => $totalItems,
                'total' => $this->formatCurrency($totalAmount),
                'days_with_vendor' => '',
                'tenure' => '',
                '_is_total' => true,
            ];
        }

        return $rows;
    }

    protected function getDailyActivity(): array
    {
        $startOfDay = $this->reportDate->copy()->startOfDay();
        $endOfDay = $this->reportDate->copy()->endOfDay();

        $memos = Memo::query()
            ->where('store_id', $this->store->id)
            ->where(function ($query) use ($startOfDay, $endOfDay) {
                $query->whereBetween('created_at', [$startOfDay, $endOfDay])
                    ->orWhereBetween('updated_at', [$startOfDay, $endOfDay]);
            })
            ->with(['vendor'])
            ->orderBy('updated_at', 'desc')
            ->get();

        $rows = $memos->map(function (Memo $memo) use ($startOfDay, $endOfDay) {
            $action = $memo->created_at->between($startOfDay, $endOfDay) ? 'Created' : 'Updated';

            return [
                'id' => $memo->id,
                'memo_number' => $this->formatLink(
                    $memo->memo_number,
                    "{$this->baseUrl}/memos/{$memo->id}"
                ),
                'vendor' => $memo->vendor?->name ?? '-',
                'status' => $this->formatBadge(
                    Str::title(str_replace('_', ' ', $memo->status)),
                    $this->getMemoStatusVariant($memo->status)
                ),
                'action' => $action,
                'total' => $this->formatCurrency($memo->total),
            ];
        })->toArray();

        if (count($rows) > 0) {
            $totalAmount = $memos->sum('total');

            $rows[] = [
                'id' => null,
                'memo_number' => ['data' => 'Totals', 'href' => ''],
                'vendor' => $memos->count().' memos',
                'status' => ['data' => '', 'variant' => 'secondary'],
                'action' => '',
                'total' => $this->formatCurrency($totalAmount),
                '_is_total' => true,
            ];
        }

        return $rows;
    }

    protected function getMemoStatusVariant(string $status): string
    {
        return match ($status) {
            Memo::STATUS_PENDING => 'warning',
            Memo::STATUS_SENT_TO_VENDOR, Memo::STATUS_VENDOR_RECEIVED => 'info',
            Memo::STATUS_VENDOR_RETURNED, Memo::STATUS_PAYMENT_RECEIVED => 'success',
            Memo::STATUS_CANCELLED => 'danger',
            default => 'secondary',
        };
    }
}
