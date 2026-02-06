<?php

namespace App\Services\Chat\Tools;

use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductReturn;
use App\Models\Transaction;

class EndOfDayTool implements ChatToolInterface
{
    public function name(): string
    {
        return 'get_end_of_day_report';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Get end of day reconciliation report. Use when user says "close out", "end of day", "daily close", "cash out", or "reconcile". Returns complete daily summary with cash breakdown.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'date' => [
                        'type' => 'string',
                        'description' => 'Date to reconcile (default today). Format: YYYY-MM-DD',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    public function execute(array $params, int $storeId): array
    {
        $date = isset($params['date'])
            ? \Carbon\Carbon::parse($params['date'])
            : now();

        $startOfDay = $date->copy()->startOfDay();
        $endOfDay = $date->copy()->endOfDay();

        // Sales summary
        $salesOrders = Order::where('store_id', $storeId)
            ->whereIn('status', Order::PAID_STATUSES)
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->get();

        $totalSales = $salesOrders->sum('total');
        $salesCount = $salesOrders->count();

        // Payments received (broken down by method)
        $payments = Payment::whereHas('invoice', function ($q) use ($storeId) {
            $q->where('store_id', $storeId);
        })
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->where('status', 'completed')
            ->get();

        $paymentsByMethod = $payments->groupBy('method')->map(function ($group) {
            return [
                'count' => $group->count(),
                'total' => round($group->sum('amount'), 2),
                'total_formatted' => '$'.number_format($group->sum('amount'), 0),
            ];
        })->toArray();

        // Buy transactions (money paid out)
        $buyTransactions = Transaction::where('store_id', $storeId)
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->whereIn('status', ['payment_processed', 'completed'])
            ->get();

        $totalBuys = $buyTransactions->sum('total');
        $buyCount = $buyTransactions->count();

        // Returns/Refunds
        $returns = ProductReturn::where('store_id', $storeId)
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->whereIn('status', ['completed', 'processed'])
            ->get();

        $totalRefunds = $returns->sum('refund_amount');
        $returnCount = $returns->count();

        // Cash calculations
        $cashIn = $payments->where('method', 'cash')->sum('amount');
        $cashOut = $buyTransactions->sum('total'); // Assuming most buys are cash
        $cashRefunds = $returns->where('refund_method', 'cash')->sum('refund_amount');
        $netCash = $cashIn - $cashOut - $cashRefunds;

        // Card totals
        $cardPayments = $payments->whereIn('method', ['credit', 'debit', 'card']);
        $cardTotal = $cardPayments->sum('amount');

        // Average metrics
        $avgTicket = $salesCount > 0 ? $totalSales / $salesCount : 0;
        $avgBuy = $buyCount > 0 ? $totalBuys / $buyCount : 0;

        return [
            'date' => $date->format('l, F j, Y'),
            'is_today' => $date->isToday(),

            'sales' => [
                'total' => round($totalSales, 2),
                'total_formatted' => '$'.number_format($totalSales, 0),
                'count' => $salesCount,
                'average_ticket' => round($avgTicket, 2),
                'average_ticket_formatted' => '$'.number_format($avgTicket, 0),
            ],

            'buys' => [
                'total' => round($totalBuys, 2),
                'total_formatted' => '$'.number_format($totalBuys, 0),
                'count' => $buyCount,
                'average' => round($avgBuy, 2),
                'average_formatted' => '$'.number_format($avgBuy, 0),
            ],

            'returns' => [
                'total' => round($totalRefunds, 2),
                'total_formatted' => '$'.number_format($totalRefunds, 0),
                'count' => $returnCount,
            ],

            'payments_by_method' => $paymentsByMethod,

            'cash_reconciliation' => [
                'cash_in' => round($cashIn, 2),
                'cash_in_formatted' => '$'.number_format($cashIn, 0),
                'cash_out_buys' => round($cashOut, 2),
                'cash_out_buys_formatted' => '$'.number_format($cashOut, 0),
                'cash_refunds' => round($cashRefunds, 2),
                'cash_refunds_formatted' => '$'.number_format($cashRefunds, 0),
                'net_cash' => round($netCash, 2),
                'net_cash_formatted' => ($netCash >= 0 ? '+' : '').'$'.number_format($netCash, 0),
            ],

            'card_total' => [
                'amount' => round($cardTotal, 2),
                'amount_formatted' => '$'.number_format($cardTotal, 0),
                'transaction_count' => $cardPayments->count(),
            ],

            'net_revenue' => [
                'amount' => round($totalSales - $totalRefunds, 2),
                'amount_formatted' => '$'.number_format($totalSales - $totalRefunds, 0),
            ],

            'summary' => $this->buildSummary(
                $totalSales,
                $salesCount,
                $totalBuys,
                $buyCount,
                $totalRefunds,
                $returnCount,
                $netCash
            ),

            'checklist' => [
                'Count the cash drawer and verify against expected: $'.number_format($netCash, 0),
                'Verify card batch total matches: $'.number_format($cardTotal, 0),
                'Review any pending orders or holds',
                'Check for items needing price updates',
                'Backup any important data',
            ],
        ];
    }

    protected function buildSummary(
        float $totalSales,
        int $salesCount,
        float $totalBuys,
        int $buyCount,
        float $totalRefunds,
        int $returnCount,
        float $netCash
    ): string {
        $parts = [];

        if ($salesCount > 0) {
            $parts[] = sprintf(
                'You made $%s in sales across %d transaction%s',
                number_format($totalSales, 0),
                $salesCount,
                $salesCount === 1 ? '' : 's'
            );
        } else {
            $parts[] = 'No sales today';
        }

        if ($buyCount > 0) {
            $parts[] = sprintf(
                'Bought %d item%s for $%s',
                $buyCount,
                $buyCount === 1 ? '' : 's',
                number_format($totalBuys, 0)
            );
        }

        if ($returnCount > 0) {
            $parts[] = sprintf(
                '%d return%s totaling $%s',
                $returnCount,
                $returnCount === 1 ? '' : 's',
                number_format($totalRefunds, 0)
            );
        }

        $parts[] = sprintf(
            'Your drawer should be %s$%s from where you started',
            $netCash >= 0 ? 'up ' : 'down ',
            number_format(abs($netCash), 0)
        );

        return implode('. ', $parts).'.';
    }
}
