<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\StoreContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesReportController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
    ) {}

    /**
     * Daily sales report - shows individual orders for a specific day.
     */
    public function daily(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $date = $request->get('date', now()->format('Y-m-d'));

        $orders = Order::query()
            ->where('store_id', $store->id)
            ->whereIn('status', Order::PAID_STATUSES)
            ->whereDate('created_at', $date)
            ->with([
                'customer.leadSource',
                'items.product.category',
                'items.variant',
                'payments' => fn ($q) => $q->where('status', Payment::STATUS_COMPLETED),
            ])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($order) {
                // Get categories from items
                $categories = $order->items
                    ->pluck('product.category.name')
                    ->filter()
                    ->unique()
                    ->values()
                    ->implode(', ');

                // Calculate cost using effective cost priority: item cost > wholesale_price > variant cost
                $cost = $order->items->sum(function ($item) {
                    // Use item's cost if set
                    if ($item->cost !== null && $item->cost > 0) {
                        return $item->cost * $item->quantity;
                    }
                    // Fallback to variant's wholesale_price or cost
                    $variant = $item->variant ?? $item->product?->variants?->first();
                    $effectiveCost = $variant?->wholesale_price ?? $variant?->cost ?? 0;

                    return $effectiveCost * $item->quantity;
                });

                $wholesaleValue = $order->items->sum(function ($item) {
                    $wholesalePrice = $item->variant?->wholesale_price ?? $item->product?->variants?->first()?->wholesale_price ?? 0;

                    return $wholesalePrice * $item->quantity;
                });

                // Get payment methods
                $paymentMethods = $order->payments
                    ->pluck('payment_method')
                    ->unique()
                    ->implode(', ');

                // Calculate profit: subtotal + service_fee - effective_cost
                $serviceFee = (float) ($order->service_fee_value ?? 0);
                $profit = ($order->sub_total ?? 0) + $serviceFee - $cost;

                return [
                    'id' => $order->id,
                    'date' => $order->created_at->format('Y-m-d H:i'),
                    'order_id' => $order->invoice_number ?? "#{$order->id}",
                    'customer' => $order->customer?->full_name ?? 'Walk-in',
                    'lead' => $order->customer?->leadSource?->name ?? '-',
                    'status' => $order->status,
                    'marketplace' => $order->source_platform ?? 'In Store',
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
                    'payment_type' => $paymentMethods ?: '-',
                    'vendor' => '-',
                ];
            });

        // Calculate totals
        $totals = [
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

        return Inertia::render('reports/sales/Daily', [
            'orders' => $orders,
            'totals' => $totals,
            'date' => $date,
        ]);
    }

    /**
     * Month over month report - past 13 months aggregated.
     */
    public function monthly(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();

        // Get past 13 months of data
        $startDate = now()->subMonths(12)->startOfMonth();
        $endDate = now()->endOfMonth();

        $monthlyData = $this->getMonthlyAggregatedData($store->id, $startDate, $endDate);

        // Calculate totals
        $totals = [
            'sales_count' => $monthlyData->sum('sales_count'),
            'items_sold' => $monthlyData->sum('items_sold'),
            'total_cost' => $monthlyData->sum('total_cost'),
            'total_wholesale_value' => $monthlyData->sum('total_wholesale_value'),
            'total_sales_price' => $monthlyData->sum('total_sales_price'),
            'total_shopify' => $monthlyData->sum('total_shopify'),
            'total_reb' => $monthlyData->sum('total_reb'),
            'total_paid' => $monthlyData->sum('total_paid'),
            'gross_profit' => $monthlyData->sum('gross_profit'),
            'profit_percent' => $monthlyData->sum('total_sales_price') > 0
                ? ($monthlyData->sum('gross_profit') / $monthlyData->sum('total_sales_price')) * 100
                : 0,
        ];

        return Inertia::render('reports/sales/Monthly', [
            'monthlyData' => $monthlyData,
            'totals' => $totals,
        ]);
    }

    /**
     * Month to date report.
     */
    public function monthToDate(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();

        $startDate = now()->startOfMonth();
        $endDate = now();

        // For MTD, we show daily breakdown
        $dailyData = $this->getDailyAggregatedData($store->id, $startDate, $endDate);

        // Calculate totals
        $totals = [
            'sales_count' => $dailyData->sum('sales_count'),
            'items_sold' => $dailyData->sum('items_sold'),
            'total_cost' => $dailyData->sum('total_cost'),
            'total_wholesale_value' => $dailyData->sum('total_wholesale_value'),
            'total_sales_price' => $dailyData->sum('total_sales_price'),
            'total_shopify' => $dailyData->sum('total_shopify'),
            'total_reb' => $dailyData->sum('total_reb'),
            'total_paid' => $dailyData->sum('total_paid'),
            'gross_profit' => $dailyData->sum('gross_profit'),
            'profit_percent' => $dailyData->sum('total_sales_price') > 0
                ? ($dailyData->sum('gross_profit') / $dailyData->sum('total_sales_price')) * 100
                : 0,
        ];

        return Inertia::render('reports/sales/MonthToDate', [
            'dailyData' => $dailyData,
            'totals' => $totals,
            'month' => now()->format('F Y'),
        ]);
    }

    /**
     * Export daily report to CSV.
     */
    public function exportDaily(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $date = $request->get('date', now()->format('Y-m-d'));

        $orders = Order::query()
            ->where('store_id', $store->id)
            ->whereIn('status', Order::PAID_STATUSES)
            ->whereDate('created_at', $date)
            ->with([
                'customer.leadSource',
                'items.product.category',
                'items.variant',
                'payments' => fn ($q) => $q->where('status', Payment::STATUS_COMPLETED),
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        $filename = "sales-report-daily-{$date}.csv";

        return response()->streamDownload(function () use ($orders) {
            $handle = fopen('php://output', 'w');

            // Header row
            fputcsv($handle, [
                'Date',
                'Order ID',
                'Customer',
                'Lead',
                'Status',
                'Marketplace',
                'Number of Items',
                'Categories',
                'Cost',
                'Wholesale Value',
                'Sub Total',
                'Profit',
                'Tax',
                'Shipping Cost',
                'Total',
                'Payment Type',
                'Vendor',
            ]);

            $totalCost = 0;
            $totalWholesale = 0;
            $totalSubTotal = 0;
            $totalProfit = 0;
            $totalTax = 0;
            $totalShipping = 0;
            $totalTotal = 0;
            $totalItems = 0;

            foreach ($orders as $order) {
                $categories = $order->items
                    ->pluck('product.category.name')
                    ->filter()
                    ->unique()
                    ->values()
                    ->implode(', ');

                // Calculate cost using effective cost priority: item cost > wholesale_price > variant cost
                $cost = $order->items->sum(function ($item) {
                    if ($item->cost !== null && $item->cost > 0) {
                        return $item->cost * $item->quantity;
                    }
                    $variant = $item->variant ?? $item->product?->variants?->first();
                    $effectiveCost = $variant?->wholesale_price ?? $variant?->cost ?? 0;

                    return $effectiveCost * $item->quantity;
                });

                $wholesaleValue = $order->items->sum(function ($item) {
                    $wholesalePrice = $item->variant?->wholesale_price ?? $item->product?->variants?->first()?->wholesale_price ?? 0;

                    return $wholesalePrice * $item->quantity;
                });

                $paymentMethods = $order->payments
                    ->pluck('payment_method')
                    ->unique()
                    ->implode(', ');

                // Calculate profit: subtotal + service_fee - effective_cost
                $serviceFee = (float) ($order->service_fee_value ?? 0);
                $profit = ($order->sub_total ?? 0) + $serviceFee - $cost;
                $numItems = $order->items->sum('quantity');

                fputcsv($handle, [
                    $order->created_at->format('Y-m-d H:i'),
                    $order->invoice_number ?? "#{$order->id}",
                    $order->customer?->full_name ?? 'Walk-in',
                    $order->customer?->leadSource?->name ?? '-',
                    $order->status,
                    $order->source_platform ?? 'In Store',
                    $numItems,
                    $categories ?: '-',
                    number_format($cost, 2),
                    number_format($wholesaleValue, 2),
                    number_format($order->sub_total ?? 0, 2),
                    number_format($profit, 2),
                    number_format($order->sales_tax ?? 0, 2),
                    number_format($order->shipping_cost ?? 0, 2),
                    number_format($order->total ?? 0, 2),
                    $paymentMethods ?: '-',
                    '-',
                ]);

                $totalItems += $numItems;
                $totalCost += $cost;
                $totalWholesale += $wholesaleValue;
                $totalSubTotal += $order->sub_total ?? 0;
                $totalProfit += $profit;
                $totalTax += $order->sales_tax ?? 0;
                $totalShipping += $order->shipping_cost ?? 0;
                $totalTotal += $order->total ?? 0;
            }

            // Totals row
            fputcsv($handle, [
                'TOTALS',
                '',
                '',
                '',
                '',
                '',
                $totalItems,
                '',
                number_format($totalCost, 2),
                number_format($totalWholesale, 2),
                number_format($totalSubTotal, 2),
                number_format($totalProfit, 2),
                number_format($totalTax, 2),
                number_format($totalShipping, 2),
                number_format($totalTotal, 2),
                '',
                '',
            ]);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Export monthly report to CSV.
     */
    public function exportMonthly(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->subMonths(12)->startOfMonth();
        $endDate = now()->endOfMonth();

        $monthlyData = $this->getMonthlyAggregatedData($store->id, $startDate, $endDate);

        $filename = 'sales-report-monthly-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($monthlyData) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Date',
                'Sales #',
                'Items Sold',
                'Total Cost',
                'Total Wholesale Value',
                'Total Sales Price',
                'Total Shopify',
                'Total Reb',
                'Total Paid',
                'Gross Profit',
                'Profit %',
            ]);

            foreach ($monthlyData as $row) {
                fputcsv($handle, [
                    $row['date'],
                    $row['sales_count'],
                    $row['items_sold'],
                    number_format($row['total_cost'], 2),
                    number_format($row['total_wholesale_value'], 2),
                    number_format($row['total_sales_price'], 2),
                    number_format($row['total_shopify'], 2),
                    number_format($row['total_reb'], 2),
                    number_format($row['total_paid'], 2),
                    number_format($row['gross_profit'], 2),
                    number_format($row['profit_percent'], 2).'%',
                ]);
            }

            // Totals row
            $totals = [
                'sales_count' => $monthlyData->sum('sales_count'),
                'items_sold' => $monthlyData->sum('items_sold'),
                'total_cost' => $monthlyData->sum('total_cost'),
                'total_wholesale_value' => $monthlyData->sum('total_wholesale_value'),
                'total_sales_price' => $monthlyData->sum('total_sales_price'),
                'total_shopify' => $monthlyData->sum('total_shopify'),
                'total_reb' => $monthlyData->sum('total_reb'),
                'total_paid' => $monthlyData->sum('total_paid'),
                'gross_profit' => $monthlyData->sum('gross_profit'),
            ];

            $profitPercent = $totals['total_sales_price'] > 0
                ? ($totals['gross_profit'] / $totals['total_sales_price']) * 100
                : 0;

            fputcsv($handle, [
                'TOTALS',
                $totals['sales_count'],
                $totals['items_sold'],
                number_format($totals['total_cost'], 2),
                number_format($totals['total_wholesale_value'], 2),
                number_format($totals['total_sales_price'], 2),
                number_format($totals['total_shopify'], 2),
                number_format($totals['total_reb'], 2),
                number_format($totals['total_paid'], 2),
                number_format($totals['gross_profit'], 2),
                number_format($profitPercent, 2).'%',
            ]);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Export month to date report to CSV.
     */
    public function exportMonthToDate(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->startOfMonth();
        $endDate = now();

        $dailyData = $this->getDailyAggregatedData($store->id, $startDate, $endDate);

        $filename = 'sales-report-mtd-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($dailyData) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Date',
                'Sales #',
                'Items Sold',
                'Total Cost',
                'Total Wholesale Value',
                'Total Sales Price',
                'Total Shopify',
                'Total Reb',
                'Total Paid',
                'Gross Profit',
                'Profit %',
            ]);

            foreach ($dailyData as $row) {
                fputcsv($handle, [
                    $row['date'],
                    $row['sales_count'],
                    $row['items_sold'],
                    number_format($row['total_cost'], 2),
                    number_format($row['total_wholesale_value'], 2),
                    number_format($row['total_sales_price'], 2),
                    number_format($row['total_shopify'], 2),
                    number_format($row['total_reb'], 2),
                    number_format($row['total_paid'], 2),
                    number_format($row['gross_profit'], 2),
                    number_format($row['profit_percent'], 2).'%',
                ]);
            }

            // Totals row
            $totals = [
                'sales_count' => $dailyData->sum('sales_count'),
                'items_sold' => $dailyData->sum('items_sold'),
                'total_cost' => $dailyData->sum('total_cost'),
                'total_wholesale_value' => $dailyData->sum('total_wholesale_value'),
                'total_sales_price' => $dailyData->sum('total_sales_price'),
                'total_shopify' => $dailyData->sum('total_shopify'),
                'total_reb' => $dailyData->sum('total_reb'),
                'total_paid' => $dailyData->sum('total_paid'),
                'gross_profit' => $dailyData->sum('gross_profit'),
            ];

            $profitPercent = $totals['total_sales_price'] > 0
                ? ($totals['gross_profit'] / $totals['total_sales_price']) * 100
                : 0;

            fputcsv($handle, [
                'TOTALS',
                $totals['sales_count'],
                $totals['items_sold'],
                number_format($totals['total_cost'], 2),
                number_format($totals['total_wholesale_value'], 2),
                number_format($totals['total_sales_price'], 2),
                number_format($totals['total_shopify'], 2),
                number_format($totals['total_reb'], 2),
                number_format($totals['total_paid'], 2),
                number_format($totals['gross_profit'], 2),
                number_format($profitPercent, 2).'%',
            ]);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Get monthly aggregated data.
     */
    protected function getMonthlyAggregatedData(int $storeId, Carbon $startDate, Carbon $endDate)
    {
        $orders = Order::query()
            ->where('store_id', $storeId)
            ->whereIn('status', Order::PAID_STATUSES)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with([
                'items.variant',
                'payments' => fn ($q) => $q->where('status', Payment::STATUS_COMPLETED),
            ])
            ->get();

        // Group by month
        $grouped = $orders->groupBy(fn ($order) => $order->created_at->format('Y-m'));

        // Generate all months in range
        $months = collect();
        $current = $startDate->copy();
        while ($current <= $endDate) {
            $key = $current->format('Y-m');
            $monthOrders = $grouped->get($key, collect());

            $totalCost = 0;
            $totalWholesaleValue = 0;
            $itemsSold = 0;
            $totalShopify = 0;
            $totalReb = 0;
            $totalServiceFee = 0;

            foreach ($monthOrders as $order) {
                foreach ($order->items as $item) {
                    // Calculate cost using effective cost priority: item cost > wholesale_price > variant cost
                    if ($item->cost !== null && $item->cost > 0) {
                        $cost = $item->cost * $item->quantity;
                    } else {
                        $effectiveCost = $item->variant?->wholesale_price ?? $item->variant?->cost ?? 0;
                        $cost = $effectiveCost * $item->quantity;
                    }
                    $wholesalePrice = $item->variant?->wholesale_price ?? 0;

                    $totalCost += $cost;
                    $totalWholesaleValue += $wholesalePrice * $item->quantity;
                    $itemsSold += $item->quantity;
                }

                // Add service fee
                $totalServiceFee += (float) ($order->service_fee_value ?? 0);

                // Count by platform
                if ($order->source_platform === 'shopify') {
                    $totalShopify += $order->total ?? 0;
                } elseif ($order->source_platform === 'reb') {
                    $totalReb += $order->total ?? 0;
                }
            }

            $totalSalesPrice = $monthOrders->sum('sub_total');
            $totalPaid = $monthOrders->sum(fn ($o) => $o->payments->sum('amount'));
            // Profit = subtotal + service_fee - effective_cost
            $grossProfit = $totalSalesPrice + $totalServiceFee - $totalCost;
            $profitPercent = $totalSalesPrice > 0 ? ($grossProfit / $totalSalesPrice) * 100 : 0;

            $months->push([
                'date' => $current->format('M Y'),
                'sales_count' => $monthOrders->count(),
                'items_sold' => $itemsSold,
                'total_cost' => $totalCost,
                'total_wholesale_value' => $totalWholesaleValue,
                'total_sales_price' => $totalSalesPrice,
                'total_shopify' => $totalShopify,
                'total_reb' => $totalReb,
                'total_paid' => $totalPaid,
                'gross_profit' => $grossProfit,
                'profit_percent' => $profitPercent,
            ]);

            $current->addMonth();
        }

        return $months;
    }

    /**
     * Get daily aggregated data.
     */
    protected function getDailyAggregatedData(int $storeId, Carbon $startDate, Carbon $endDate)
    {
        $orders = Order::query()
            ->where('store_id', $storeId)
            ->whereIn('status', Order::PAID_STATUSES)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with([
                'items.variant',
                'payments' => fn ($q) => $q->where('status', Payment::STATUS_COMPLETED),
            ])
            ->get();

        // Group by day
        $grouped = $orders->groupBy(fn ($order) => $order->created_at->format('Y-m-d'));

        // Generate all days in range
        $days = collect();
        $current = $startDate->copy();
        while ($current <= $endDate) {
            $key = $current->format('Y-m-d');
            $dayOrders = $grouped->get($key, collect());

            $totalCost = 0;
            $totalWholesaleValue = 0;
            $itemsSold = 0;
            $totalShopify = 0;
            $totalReb = 0;
            $totalServiceFee = 0;

            foreach ($dayOrders as $order) {
                foreach ($order->items as $item) {
                    // Calculate cost using effective cost priority: item cost > wholesale_price > variant cost
                    if ($item->cost !== null && $item->cost > 0) {
                        $cost = $item->cost * $item->quantity;
                    } else {
                        $effectiveCost = $item->variant?->wholesale_price ?? $item->variant?->cost ?? 0;
                        $cost = $effectiveCost * $item->quantity;
                    }
                    $wholesalePrice = $item->variant?->wholesale_price ?? 0;

                    $totalCost += $cost;
                    $totalWholesaleValue += $wholesalePrice * $item->quantity;
                    $itemsSold += $item->quantity;
                }

                // Add service fee
                $totalServiceFee += (float) ($order->service_fee_value ?? 0);

                // Count by platform
                if ($order->source_platform === 'shopify') {
                    $totalShopify += $order->total ?? 0;
                } elseif ($order->source_platform === 'reb') {
                    $totalReb += $order->total ?? 0;
                }
            }

            $totalSalesPrice = $dayOrders->sum('sub_total');
            $totalPaid = $dayOrders->sum(fn ($o) => $o->payments->sum('amount'));
            // Profit = subtotal + service_fee - effective_cost
            $grossProfit = $totalSalesPrice + $totalServiceFee - $totalCost;
            $profitPercent = $totalSalesPrice > 0 ? ($grossProfit / $totalSalesPrice) * 100 : 0;

            $days->push([
                'date' => $current->format('M d, Y'),
                'sales_count' => $dayOrders->count(),
                'items_sold' => $itemsSold,
                'total_cost' => $totalCost,
                'total_wholesale_value' => $totalWholesaleValue,
                'total_sales_price' => $totalSalesPrice,
                'total_shopify' => $totalShopify,
                'total_reb' => $totalReb,
                'total_paid' => $totalPaid,
                'gross_profit' => $grossProfit,
                'profit_percent' => $profitPercent,
            ]);

            $current->addDay();
        }

        return $days;
    }
}
