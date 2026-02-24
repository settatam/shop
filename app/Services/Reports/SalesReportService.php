<?php

namespace App\Services\Reports;

use App\Models\Order;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Service class for sales report data aggregation.
 *
 * Single source of truth for sales data calculations, used by both
 * SalesReportController (web UI) and LegacySalesReport (email).
 */
class SalesReportService
{
    /**
     * Payment method labels for display.
     */
    protected const PAYMENT_METHOD_LABELS = [
        'cash' => 'Cash',
        'card' => 'Card',
        'store_credit' => 'Store Credit',
        'layaway' => 'Layaway',
        'external' => 'External',
        'check' => 'Check',
        'bank_transfer' => 'Bank Transfer',
        'paypal' => 'PayPal',
        'venmo' => 'Venmo',
        'zelle' => 'Zelle',
        'wire' => 'Wire Transfer',
        'crypto' => 'Crypto',
    ];

    /**
     * Format payment method for display.
     */
    public function formatPaymentMethod(string $method): string
    {
        return self::PAYMENT_METHOD_LABELS[$method] ?? ucwords(str_replace('_', ' ', $method));
    }

    /**
     * Format payment methods from a collection of payments.
     */
    public function formatPaymentMethods(Collection $payments): string
    {
        $methods = $payments
            ->pluck('payment_method')
            ->unique()
            ->map(fn ($method) => $this->formatPaymentMethod($method ?? ''))
            ->filter()
            ->implode(', ');

        return $methods ?: '-';
    }

    /**
     * Get daily sales (individual orders) for a date range.
     *
     * @return Collection<int, array>
     */
    public function getDailySales(int $storeId, Carbon $startDate, Carbon $endDate, ?array $categoryIds = null): Collection
    {
        $ordersQuery = Order::query()
            ->where('store_id', $storeId)
            ->whereIn('status', Order::PAID_STATUSES)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with([
                'customer.leadSource',
                'items.product.category',
                'items.category',
                'items.variant',
                'salesChannel',
                'platformOrder',
                'payments' => fn ($q) => $q->where('status', Payment::STATUS_COMPLETED),
            ]);

        if ($categoryIds) {
            $ordersQuery->whereHas('items', function ($q) use ($categoryIds) {
                $q->where(function ($q2) use ($categoryIds) {
                    $q2->whereIn('category_id', $categoryIds)
                        ->orWhereHas('product', function ($q3) use ($categoryIds) {
                            $q3->whereIn('category_id', $categoryIds);
                        });
                });
            });
        }

        return $ordersQuery->orderBy('created_at', 'desc')->get()
            ->map(function ($order) {
                $categories = $order->items
                    ->pluck('product.category.name')
                    ->filter()
                    ->unique()
                    ->values()
                    ->implode(', ');

                $wholesaleValue = $order->items->sum(function ($item) {
                    $wholesalePrice = $item->wholesale_value ?? $item->variant?->wholesale_price ?? 0;

                    return $wholesalePrice * $item->quantity;
                });

                $cost = $order->items->sum(function ($item) {
                    $wholesalePrice = $item->wholesale_value ?? $item->variant?->wholesale_price ?? 0;
                    $costOfItem = $item->cost ?? $item->variant?->cost ?? 0;
                    $effectiveCost = $wholesalePrice > 0 ? $wholesalePrice : $costOfItem;

                    return $effectiveCost * $item->quantity;
                });

                $paymentMethods = $this->formatPaymentMethods($order->payments);
                $serviceFee = (float) ($order->service_fee_value ?? 0);
                $profit = ($order->sub_total ?? 0) + $serviceFee - $cost;
                $channelName = $order->salesChannel?->name ?? $order->source_platform ?? 'In Store';

                return [
                    'id' => $order->id,
                    'date' => $order->created_at->format('Y-m-d H:i'),
                    'order_id' => $order->invoice_number ?? "#{$order->id}",
                    'customer' => $order->customer?->full_name ?? 'Walk-in',
                    'lead' => $order->customer?->leadSource?->name ?? '-',
                    'status' => $order->status,
                    'marketplace' => $channelName,
                    'num_items' => $order->items->sum('quantity'),
                    'categories' => $categories ?: '-',
                    'cost' => $cost,
                    'wholesale_value' => $wholesaleValue,
                    'sub_total' => $order->sub_total ?? 0,
                    'service_fee' => $serviceFee,
                    'profit' => $profit,
                    'tax' => $order->sales_tax ?? 0,
                    'shipping_cost' => $order->shipping_cost ?? 0,
                    'total' => $order->total ?? 0,
                    'payment_type' => $paymentMethods,
                ];
            });
    }

    /**
     * Calculate totals for a collection of sales rows.
     */
    public function calculateDailySalesTotals(Collection $orders): array
    {
        return [
            'num_items' => $orders->sum('num_items'),
            'cost' => $orders->sum('cost'),
            'wholesale_value' => $orders->sum('wholesale_value'),
            'sub_total' => $orders->sum('sub_total'),
            'service_fee' => $orders->sum('service_fee'),
            'profit' => $orders->sum('profit'),
            'tax' => $orders->sum('tax'),
            'shipping_cost' => $orders->sum('shipping_cost'),
            'total' => $orders->sum('total'),
        ];
    }

    /**
     * Get daily aggregated data for a date range.
     *
     * @return Collection<int, array>
     */
    public function getDailyAggregatedData(int $storeId, Carbon $startDate, Carbon $endDate, ?array $categoryIds = null): Collection
    {
        $ordersQuery = Order::query()
            ->where('store_id', $storeId)
            ->whereIn('status', Order::PAID_STATUSES)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with([
                'items.variant',
                'items.product',
                'payments' => fn ($q) => $q->where('status', Payment::STATUS_COMPLETED),
            ]);

        if ($categoryIds) {
            $ordersQuery->whereHas('items', function ($q) use ($categoryIds) {
                $q->where(function ($q2) use ($categoryIds) {
                    $q2->whereIn('category_id', $categoryIds)
                        ->orWhereHas('product', function ($q3) use ($categoryIds) {
                            $q3->whereIn('category_id', $categoryIds);
                        });
                });
            });
        }

        $orders = $ordersQuery->get();
        $grouped = $orders->groupBy(fn ($order) => $order->created_at->format('Y-m-d'));

        $days = collect();
        $current = $startDate->copy();

        while ($current <= $endDate) {
            $key = $current->format('Y-m-d');
            $dayOrders = $grouped->get($key, collect());

            $aggregated = $this->aggregateOrders($dayOrders);

            $days->push([
                'date' => $current->format('M d, Y'),
                'date_key' => $current->format('Y-m-d'),
                'sales_count' => $dayOrders->count(),
                'items_sold' => $aggregated['items_sold'],
                'total_cost' => $aggregated['total_cost'],
                'total_wholesale_value' => $aggregated['total_wholesale_value'],
                'total_sales_price' => $aggregated['total_sales_price'],
                'total_service_fee' => $aggregated['total_service_fee'],
                'total_tax' => $aggregated['total_tax'],
                'total_shipping' => $aggregated['total_shipping'],
                'total_paid' => $aggregated['total_paid'],
                'gross_profit' => $aggregated['gross_profit'],
                'profit_percent' => $aggregated['profit_percent'],
            ]);

            $current->addDay();
        }

        return $days->reverse()->values();
    }

    /**
     * Get monthly aggregated data for a date range.
     *
     * @return Collection<int, array>
     */
    public function getMonthlyAggregatedData(int $storeId, Carbon $startDate, Carbon $endDate, ?array $categoryIds = null): Collection
    {
        $ordersQuery = Order::query()
            ->where('store_id', $storeId)
            ->whereIn('status', Order::PAID_STATUSES)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with([
                'items.variant',
                'items.product',
                'payments' => fn ($q) => $q->where('status', Payment::STATUS_COMPLETED),
            ]);

        if ($categoryIds) {
            $ordersQuery->whereHas('items', function ($q) use ($categoryIds) {
                $q->where(function ($q2) use ($categoryIds) {
                    $q2->whereIn('category_id', $categoryIds)
                        ->orWhereHas('product', function ($q3) use ($categoryIds) {
                            $q3->whereIn('category_id', $categoryIds);
                        });
                });
            });
        }

        $orders = $ordersQuery->get();
        $grouped = $orders->groupBy(fn ($order) => $order->created_at->format('Y-m'));

        $months = collect();
        $current = $startDate->copy();

        while ($current <= $endDate) {
            $key = $current->format('Y-m');
            $monthOrders = $grouped->get($key, collect());

            $aggregated = $this->aggregateOrders($monthOrders);

            $months->push([
                'date' => $current->format('M Y'),
                'start_date' => $current->copy()->startOfMonth()->format('Y-m-d'),
                'end_date' => $current->copy()->endOfMonth()->format('Y-m-d'),
                'sales_count' => $monthOrders->count(),
                'items_sold' => $aggregated['items_sold'],
                'total_cost' => $aggregated['total_cost'],
                'total_wholesale_value' => $aggregated['total_wholesale_value'],
                'total_sales_price' => $aggregated['total_sales_price'],
                'total_service_fee' => $aggregated['total_service_fee'],
                'total_tax' => $aggregated['total_tax'],
                'total_shipping' => $aggregated['total_shipping'],
                'total_paid' => $aggregated['total_paid'],
                'gross_profit' => $aggregated['gross_profit'],
                'profit_percent' => $aggregated['profit_percent'],
            ]);

            $current->addMonth();
        }

        return $months->reverse()->values();
    }

    /**
     * Calculate totals for aggregated data.
     */
    public function calculateAggregatedTotals(Collection $data): array
    {
        $totalSalesPrice = $data->sum('total_sales_price');
        $grossProfit = $data->sum('gross_profit');

        return [
            'sales_count' => $data->sum('sales_count'),
            'items_sold' => $data->sum('items_sold'),
            'total_cost' => $data->sum('total_cost'),
            'total_wholesale_value' => $data->sum('total_wholesale_value'),
            'total_sales_price' => $totalSalesPrice,
            'total_service_fee' => $data->sum('total_service_fee'),
            'total_tax' => $data->sum('total_tax'),
            'total_shipping' => $data->sum('total_shipping'),
            'total_paid' => $data->sum('total_paid'),
            'gross_profit' => $grossProfit,
            'profit_percent' => $totalSalesPrice > 0 ? ($grossProfit / $totalSalesPrice) * 100 : 0,
        ];
    }

    /**
     * Aggregate orders into totals.
     */
    protected function aggregateOrders(Collection $orders): array
    {
        $totalCost = 0;
        $totalWholesaleValue = 0;
        $itemsSold = 0;
        $totalServiceFee = 0;
        $totalTax = 0;
        $totalShipping = 0;

        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $wholesalePrice = $item->wholesale_value ?? $item->variant?->wholesale_price ?? 0;
                $costOfItem = $item->cost ?? $item->variant?->cost ?? 0;

                $totalWholesaleValue += $wholesalePrice * $item->quantity;
                $effectiveCostForProfit = $wholesalePrice > 0 ? $wholesalePrice : $costOfItem;
                $totalCost += $effectiveCostForProfit * $item->quantity;
                $itemsSold += $item->quantity;
            }

            $totalServiceFee += (float) ($order->service_fee_value ?? 0);
            $totalTax += (float) ($order->sales_tax ?? 0);
            $totalShipping += (float) ($order->shipping_cost ?? 0);
        }

        $totalSalesPrice = $orders->sum('sub_total');
        $totalPaid = $orders->sum(fn ($o) => $o->payments->sum('amount'));
        $grossProfit = $totalSalesPrice + $totalServiceFee - $totalCost;
        $profitPercent = $totalSalesPrice > 0 ? ($grossProfit / $totalSalesPrice) * 100 : 0;

        return [
            'items_sold' => $itemsSold,
            'total_cost' => $totalCost,
            'total_wholesale_value' => $totalWholesaleValue,
            'total_sales_price' => $totalSalesPrice,
            'total_service_fee' => $totalServiceFee,
            'total_tax' => $totalTax,
            'total_shipping' => $totalShipping,
            'total_paid' => $totalPaid,
            'gross_profit' => $grossProfit,
            'profit_percent' => $profitPercent,
        ];
    }

    /**
     * Get badge variant for order status.
     */
    public function getStatusVariant(string $status): string
    {
        return match ($status) {
            'confirmed', 'completed', 'delivered' => 'success',
            'processing', 'shipped' => 'info',
            'pending' => 'warning',
            'cancelled', 'refunded' => 'danger',
            default => 'secondary',
        };
    }

    /**
     * Get a human-readable label for the date range.
     */
    public function getDateRangeLabel(Carbon $startDate, Carbon $endDate): string
    {
        if ($startDate->isSameDay($endDate)) {
            return $startDate->format('F j, Y');
        }

        if ($startDate->isSameMonth($endDate)) {
            return $startDate->format('M j').' - '.$endDate->format('j, Y');
        }

        return $startDate->format('M j, Y').' - '.$endDate->format('M j, Y');
    }
}
