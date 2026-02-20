<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SalesChannel;
use App\Services\StoreContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesReportController extends Controller
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

    public function __construct(
        protected StoreContext $storeContext,
    ) {}

    /**
     * Format payment method for display (capitalized).
     */
    protected function formatPaymentMethod(string $method): string
    {
        return self::PAYMENT_METHOD_LABELS[$method] ?? ucwords(str_replace('_', ' ', $method));
    }

    /**
     * Format payment methods from a collection.
     */
    protected function formatPaymentMethods(Collection $payments): string
    {
        $methods = $payments
            ->pluck('payment_method')
            ->unique()
            ->map(fn ($method) => $this->formatPaymentMethod($method))
            ->implode(', ');

        return $methods ?: '-';
    }

    /**
     * Format wholesale value for display (use '-' for zero values).
     */
    protected function formatWholesaleValue(float $value): string|float
    {
        return $value > 0 ? $value : '-';
    }

    /**
     * Daily sales report - shows individual orders for a date range.
     */
    public function daily(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();

        // Support date range or single date (backwards compatible)
        if ($request->filled('start_date') || $request->filled('end_date')) {
            $startDate = $request->filled('start_date')
                ? Carbon::parse($request->input('start_date'))->startOfDay()
                : now()->startOfDay();
            $endDate = $request->filled('end_date')
                ? Carbon::parse($request->input('end_date'))->endOfDay()
                : now()->endOfDay();
        } else {
            // Single date mode (legacy)
            $date = $request->get('date', now()->format('Y-m-d'));
            $startDate = Carbon::parse($date)->startOfDay();
            $endDate = Carbon::parse($date)->endOfDay();
        }

        // Ensure start is before end
        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        // Category filter - get descendant IDs if filtering
        $categoryIds = null;
        if ($request->filled('category_id')) {
            $categoryIds = $this->getCategoryDescendantIds((int) $request->category_id, $store->id);
        }

        $ordersQuery = Order::query()
            ->where('store_id', $store->id)
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

        // Apply category filter if set
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

        $ordersRaw = $ordersQuery->orderBy('created_at', 'desc')->get();

        // Get category breakdown before transforming
        $categoryBreakdown = $this->getCategoryBreakdown($ordersRaw, $store->id);

        $orders = $ordersRaw
            ->map(function ($order) {
                // Get categories from items
                $categories = $order->items
                    ->pluck('product.category.name')
                    ->filter()
                    ->unique()
                    ->values()
                    ->implode(', ');

                // Wholesale value = sum of wholesale values (stored at time of sale, fallback to variant for historical data)
                $wholesaleValue = $order->items->sum(function ($item) {
                    $wholesalePrice = $item->wholesale_value ?? $item->variant?->wholesale_price ?? 0;

                    return $wholesalePrice * $item->quantity;
                });

                // Cost for profit: use wholesale_value if exists, else cost
                $cost = $order->items->sum(function ($item) {
                    $wholesalePrice = $item->wholesale_value ?? $item->variant?->wholesale_price ?? 0;
                    $costOfItem = $item->cost ?? $item->variant?->cost ?? 0;

                    // Use wholesale_value if it exists, otherwise use cost
                    $effectiveCost = $wholesalePrice > 0 ? $wholesalePrice : $costOfItem;

                    return $effectiveCost * $item->quantity;
                });

                // Get payment methods (capitalized)
                $paymentMethods = $this->formatPaymentMethods($order->payments);

                // Calculate profit: subtotal + service_fee - (wholesale_price if exists, else cost_of_item)
                $serviceFee = (float) ($order->service_fee_value ?? 0);
                $profit = ($order->sub_total ?? 0) + $serviceFee - $cost;

                // Get channel name - prefer salesChannel relationship, fall back to source_platform
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
            'startDate' => $startDate->format('Y-m-d'),
            'endDate' => $endDate->format('Y-m-d'),
            'dateRangeLabel' => $this->getDateRangeLabel($startDate, $endDate),
            'categories' => $this->getCategories($store->id),
            'categoryBreakdown' => $categoryBreakdown,
            'filters' => $request->only(['category_id']),
        ]);
    }

    /**
     * Get a human-readable label for the date range.
     */
    protected function getDateRangeLabel(Carbon $startDate, Carbon $endDate): string
    {
        if ($startDate->isSameDay($endDate)) {
            return $startDate->format('F j, Y');
        }

        if ($startDate->isSameMonth($endDate)) {
            return $startDate->format('M j').' - '.$endDate->format('j, Y');
        }

        return $startDate->format('M j, Y').' - '.$endDate->format('M j, Y');
    }

    /**
     * Daily items report - shows individual items sold for a date range.
     */
    public function dailyItems(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();

        // Support date range or single date (backwards compatible)
        if ($request->filled('start_date') || $request->filled('end_date')) {
            $startDate = $request->filled('start_date')
                ? Carbon::parse($request->input('start_date'))->startOfDay()
                : now()->startOfDay();
            $endDate = $request->filled('end_date')
                ? Carbon::parse($request->input('end_date'))->endOfDay()
                : now()->endOfDay();
        } else {
            // Single date mode (legacy)
            $date = $request->get('date', now()->format('Y-m-d'));
            $startDate = Carbon::parse($date)->startOfDay();
            $endDate = Carbon::parse($date)->endOfDay();
        }

        // Ensure start is before end
        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $orders = Order::query()
            ->where('store_id', $store->id)
            ->whereIn('status', Order::PAID_STATUSES)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with([
                'customer.leadSource',
                'items.product.category',
                'items.variant',
                'salesChannel',
                'platformOrder',
                'payments' => fn ($q) => $q->where('status', Payment::STATUS_COMPLETED),
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        // Flatten orders to items
        $items = $orders->flatMap(function ($order) {
            $paymentMethods = $this->formatPaymentMethods($order->payments);
            $channelName = $order->salesChannel?->name ?? $order->source_platform ?? 'In Store';

            return $order->items->map(function ($item) use ($order, $paymentMethods, $channelName) {
                $wholesalePrice = $item->wholesale_value ?? $item->variant?->wholesale_price ?? 0;
                $costOfItem = $item->cost ?? $item->variant?->cost ?? 0;
                $effectiveCost = $wholesalePrice > 0 ? $wholesalePrice : $costOfItem;

                $itemTotal = ($item->unit_price ?? 0) * $item->quantity;
                $itemCost = $effectiveCost * $item->quantity;
                $profit = $itemTotal - $itemCost;

                return [
                    'id' => $item->id,
                    'order_id' => $order->id,
                    'order_number' => $order->invoice_number ?? "#{$order->id}",
                    'date' => $order->created_at->format('Y-m-d H:i'),
                    'customer' => $order->customer?->full_name ?? 'Walk-in',
                    'lead' => $order->customer?->leadSource?->name ?? '-',
                    'sku' => $item->variant?->sku ?? $item->product?->sku ?? '-',
                    'product_name' => $item->variant?->title ?? $item->product?->title ?? $item->name ?? '-',
                    'category' => $item->product?->category?->name ?? '-',
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price ?? 0,
                    'wholesale_value' => $wholesalePrice * $item->quantity,
                    'cost' => $itemCost,
                    'total' => $itemTotal,
                    'profit' => $profit,
                    'marketplace' => $channelName,
                    'payment_type' => $paymentMethods,
                    'vendor' => '-',
                ];
            });
        })->values();

        // Calculate totals
        $totals = [
            'quantity' => $items->sum('quantity'),
            'wholesale_value' => $items->sum('wholesale_value'),
            'cost' => $items->sum('cost'),
            'total' => $items->sum('total'),
            'profit' => $items->sum('profit'),
        ];

        return Inertia::render('reports/sales/DailyItems', [
            'items' => $items,
            'totals' => $totals,
            'startDate' => $startDate->format('Y-m-d'),
            'endDate' => $endDate->format('Y-m-d'),
            'dateRangeLabel' => $this->getDateRangeLabel($startDate, $endDate),
        ]);
    }

    /**
     * Export daily items report to CSV.
     */
    public function exportDailyItems(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();

        // Support date range or single date (backwards compatible)
        if ($request->filled('start_date') || $request->filled('end_date')) {
            $startDate = $request->filled('start_date')
                ? Carbon::parse($request->input('start_date'))->startOfDay()
                : now()->startOfDay();
            $endDate = $request->filled('end_date')
                ? Carbon::parse($request->input('end_date'))->endOfDay()
                : now()->endOfDay();
        } else {
            $date = $request->get('date', now()->format('Y-m-d'));
            $startDate = Carbon::parse($date)->startOfDay();
            $endDate = Carbon::parse($date)->endOfDay();
        }

        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $orders = Order::query()
            ->where('store_id', $store->id)
            ->whereIn('status', Order::PAID_STATUSES)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with([
                'customer.leadSource',
                'items.product.category',
                'items.variant',
                'salesChannel',
                'platformOrder',
                'payments' => fn ($q) => $q->where('status', Payment::STATUS_COMPLETED),
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        $filename = 'sales-report-daily-items-'.$startDate->format('Y-m-d').'-to-'.$endDate->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($orders) {
            $handle = fopen('php://output', 'w');

            // Header row
            fputcsv($handle, [
                'Date',
                'Order #',
                'Customer',
                'Lead',
                'SKU',
                'Product Name',
                'Category',
                'Qty',
                'Unit Price',
                'Wholesale',
                'Cost',
                'Total',
                'Profit',
                'Channel',
                'Payment Type',
                'Vendor',
            ]);

            $totalQty = 0;
            $totalWholesale = 0;
            $totalCost = 0;
            $totalTotal = 0;
            $totalProfit = 0;

            foreach ($orders as $order) {
                $paymentMethods = $this->formatPaymentMethods($order->payments);
                $channelName = $order->salesChannel?->name ?? $order->source_platform ?? 'In Store';

                foreach ($order->items as $item) {
                    $wholesalePrice = $item->wholesale_value ?? $item->variant?->wholesale_price ?? 0;
                    $costOfItem = $item->cost ?? $item->variant?->cost ?? 0;
                    $effectiveCost = $wholesalePrice > 0 ? $wholesalePrice : $costOfItem;

                    $itemTotal = ($item->unit_price ?? 0) * $item->quantity;
                    $itemCost = $effectiveCost * $item->quantity;
                    $profit = $itemTotal - $itemCost;

                    $wholesaleDisplay = $wholesalePrice > 0 ? number_format($wholesalePrice * $item->quantity, 2) : '-';
                    $costDisplay = $itemCost > 0 ? number_format($itemCost, 2) : '-';

                    fputcsv($handle, [
                        $order->created_at->format('Y-m-d H:i'),
                        $order->invoice_number ?? "#{$order->id}",
                        $order->customer?->full_name ?? 'Walk-in',
                        $order->customer?->leadSource?->name ?? '-',
                        $item->variant?->sku ?? $item->product?->sku ?? '-',
                        $item->variant?->title ?? $item->product?->title ?? $item->name ?? '-',
                        $item->product?->category?->name ?? '-',
                        $item->quantity,
                        number_format($item->unit_price ?? 0, 2),
                        $wholesaleDisplay,
                        $costDisplay,
                        number_format($itemTotal, 2),
                        number_format($profit, 2),
                        $channelName,
                        $paymentMethods,
                        '-',
                    ]);

                    $totalQty += $item->quantity;
                    $totalWholesale += $wholesalePrice > 0 ? $wholesalePrice * $item->quantity : 0;
                    $totalCost += $itemCost;
                    $totalTotal += $itemTotal;
                    $totalProfit += $profit;
                }
            }

            // Totals row
            fputcsv($handle, [
                'TOTALS',
                '',
                '',
                '',
                '',
                '',
                '',
                $totalQty,
                '',
                $totalWholesale > 0 ? number_format($totalWholesale, 2) : '-',
                $totalCost > 0 ? number_format($totalCost, 2) : '-',
                number_format($totalTotal, 2),
                number_format($totalProfit, 2),
                '',
                '',
                '',
            ]);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Month over month report - aggregated by month.
     */
    public function monthly(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();

        // Parse month/year range from request, default to last 12 months
        $startMonth = $request->input('start_month', now()->subMonths(12)->month);
        $startYear = $request->input('start_year', now()->subMonths(12)->year);
        $endMonth = $request->input('end_month', now()->month);
        $endYear = $request->input('end_year', now()->year);

        $startDate = Carbon::createFromDate($startYear, $startMonth, 1)->startOfMonth();
        $endDate = Carbon::createFromDate($endYear, $endMonth, 1)->endOfMonth();

        // Ensure start is before end
        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        // Category filter - get descendant IDs if filtering
        $categoryIds = null;
        if ($request->filled('category_id')) {
            $categoryIds = $this->getCategoryDescendantIds((int) $request->category_id, $store->id);
        }

        // Get sales channels for the store
        $channels = $this->getSalesChannels($store->id);

        $monthlyData = $this->getMonthlyAggregatedData($store->id, $startDate, $endDate, $channels, $categoryIds);

        // Calculate totals
        $totals = [
            'sales_count' => $monthlyData->sum('sales_count'),
            'items_sold' => $monthlyData->sum('items_sold'),
            'total_cost' => $monthlyData->sum('total_cost'),
            'total_wholesale_value' => $monthlyData->sum('total_wholesale_value'),
            'total_sales_price' => $monthlyData->sum('total_sales_price'),
            'total_service_fee' => $monthlyData->sum('total_service_fee'),
            'total_tax' => $monthlyData->sum('total_tax'),
            'total_shipping' => $monthlyData->sum('total_shipping'),
            'total_paid' => $monthlyData->sum('total_paid'),
            'gross_profit' => $monthlyData->sum('gross_profit'),
            'profit_percent' => $monthlyData->sum('total_sales_price') > 0
                ? ($monthlyData->sum('gross_profit') / $monthlyData->sum('total_sales_price')) * 100
                : 0,
        ];

        // Add channel totals dynamically
        foreach ($channels as $channel) {
            $key = 'total_'.$channel['code'];
            $totals[$key] = $monthlyData->sum($key);
        }

        // Get category breakdown from orders for the period
        $ordersForBreakdown = Order::query()
            ->where('store_id', $store->id)
            ->whereIn('status', Order::PAID_STATUSES)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with(['items.product.category', 'items.category', 'items.variant']);

        if ($categoryIds) {
            $ordersForBreakdown->whereHas('items', function ($q) use ($categoryIds) {
                $q->where(function ($q2) use ($categoryIds) {
                    $q2->whereIn('category_id', $categoryIds)
                        ->orWhereHas('product', function ($q3) use ($categoryIds) {
                            $q3->whereIn('category_id', $categoryIds);
                        });
                });
            });
        }

        $categoryBreakdown = $this->getCategoryBreakdown($ordersForBreakdown->get(), $store->id);

        return Inertia::render('reports/sales/Monthly', [
            'monthlyData' => $monthlyData,
            'totals' => $totals,
            'channels' => $channels,
            'startMonth' => $startDate->month,
            'startYear' => $startDate->year,
            'endMonth' => $endDate->month,
            'endYear' => $endDate->year,
            'dateRangeLabel' => $startDate->format('M Y').' - '.$endDate->format('M Y'),
            'categories' => $this->getCategories($store->id),
            'categoryBreakdown' => $categoryBreakdown,
            'filters' => $request->only(['category_id']),
        ]);
    }

    /**
     * Month to date report (daily breakdown for a date range).
     */
    public function monthToDate(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();

        // Parse date range from request, default to current month
        $startDate = $request->filled('start_date')
            ? Carbon::parse($request->input('start_date'))->startOfDay()
            : now()->startOfMonth();

        $endDate = $request->filled('end_date')
            ? Carbon::parse($request->input('end_date'))->endOfDay()
            : now()->endOfDay();

        // Ensure start is before end
        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        // Category filter - get descendant IDs if filtering
        $categoryIds = null;
        if ($request->filled('category_id')) {
            $categoryIds = $this->getCategoryDescendantIds((int) $request->category_id, $store->id);
        }

        // Get sales channels for the store
        $channels = $this->getSalesChannels($store->id);

        // For MTD, we show daily breakdown
        $dailyData = $this->getDailyAggregatedData($store->id, $startDate, $endDate, $channels, $categoryIds);

        // Calculate totals
        $totals = [
            'sales_count' => $dailyData->sum('sales_count'),
            'items_sold' => $dailyData->sum('items_sold'),
            'total_cost' => $dailyData->sum('total_cost'),
            'total_wholesale_value' => $dailyData->sum('total_wholesale_value'),
            'total_sales_price' => $dailyData->sum('total_sales_price'),
            'total_service_fee' => $dailyData->sum('total_service_fee'),
            'total_tax' => $dailyData->sum('total_tax'),
            'total_shipping' => $dailyData->sum('total_shipping'),
            'total_paid' => $dailyData->sum('total_paid'),
            'gross_profit' => $dailyData->sum('gross_profit'),
            'profit_percent' => $dailyData->sum('total_sales_price') > 0
                ? ($dailyData->sum('gross_profit') / $dailyData->sum('total_sales_price')) * 100
                : 0,
        ];

        // Add channel totals dynamically
        foreach ($channels as $channel) {
            $key = 'total_'.$channel['code'];
            $totals[$key] = $dailyData->sum($key);
        }

        // Get category breakdown from orders for the period
        $ordersForBreakdown = Order::query()
            ->where('store_id', $store->id)
            ->whereIn('status', Order::PAID_STATUSES)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with(['items.product.category', 'items.category', 'items.variant']);

        if ($categoryIds) {
            $ordersForBreakdown->whereHas('items', function ($q) use ($categoryIds) {
                $q->where(function ($q2) use ($categoryIds) {
                    $q2->whereIn('category_id', $categoryIds)
                        ->orWhereHas('product', function ($q3) use ($categoryIds) {
                            $q3->whereIn('category_id', $categoryIds);
                        });
                });
            });
        }

        $categoryBreakdown = $this->getCategoryBreakdown($ordersForBreakdown->get(), $store->id);

        return Inertia::render('reports/sales/MonthToDate', [
            'dailyData' => $dailyData,
            'totals' => $totals,
            'startDate' => $startDate->format('Y-m-d'),
            'endDate' => $endDate->format('Y-m-d'),
            'dateRangeLabel' => $this->getDateRangeLabel($startDate, $endDate),
            'channels' => $channels,
            'categories' => $this->getCategories($store->id),
            'categoryBreakdown' => $categoryBreakdown,
            'filters' => $request->only(['category_id']),
        ]);
    }

    /**
     * Export daily report to CSV.
     */
    public function exportDaily(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();

        // Support date range or single date (backwards compatible)
        if ($request->filled('start_date') || $request->filled('end_date')) {
            $startDate = $request->filled('start_date')
                ? Carbon::parse($request->input('start_date'))->startOfDay()
                : now()->startOfDay();
            $endDate = $request->filled('end_date')
                ? Carbon::parse($request->input('end_date'))->endOfDay()
                : now()->endOfDay();
        } else {
            $date = $request->get('date', now()->format('Y-m-d'));
            $startDate = Carbon::parse($date)->startOfDay();
            $endDate = Carbon::parse($date)->endOfDay();
        }

        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $orders = Order::query()
            ->where('store_id', $store->id)
            ->whereIn('status', Order::PAID_STATUSES)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with([
                'customer.leadSource',
                'items.product.category',
                'items.variant',
                'salesChannel',
                'platformOrder',
                'payments' => fn ($q) => $q->where('status', Payment::STATUS_COMPLETED),
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        $filename = 'sales-report-daily-'.$startDate->format('Y-m-d').'-to-'.$endDate->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($orders) {
            $handle = fopen('php://output', 'w');

            // Header row
            fputcsv($handle, [
                'Date',
                'Order ID',
                'Customer',
                'Lead',
                'Status',
                'Channel',
                'Number of Items',
                'Categories',
                'Cost',
                'Wholesale Value',
                'Sub Total',
                'Service Fee',
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
            $totalServiceFee = 0;
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

                // Wholesale value = sum of wholesale values (stored at time of sale, fallback to variant for historical data)
                $wholesaleValue = $order->items->sum(function ($item) {
                    $wholesalePrice = $item->wholesale_value ?? $item->variant?->wholesale_price ?? 0;

                    return $wholesalePrice * $item->quantity;
                });

                // Cost for profit: use wholesale_value if exists, else cost
                $cost = $order->items->sum(function ($item) {
                    $wholesalePrice = $item->wholesale_value ?? $item->variant?->wholesale_price ?? 0;
                    $costOfItem = $item->cost ?? $item->variant?->cost ?? 0;

                    // Use wholesale_value if it exists, otherwise use cost
                    $effectiveCost = $wholesalePrice > 0 ? $wholesalePrice : $costOfItem;

                    return $effectiveCost * $item->quantity;
                });

                // Get payment methods (capitalized)
                $paymentMethods = $this->formatPaymentMethods($order->payments);

                // Calculate profit: subtotal + service_fee - (wholesale_price if exists, else cost_of_item)
                $serviceFee = (float) ($order->service_fee_value ?? 0);
                $profit = ($order->sub_total ?? 0) + $serviceFee - $cost;
                $numItems = $order->items->sum('quantity');

                // Get channel name
                $channelName = $order->salesChannel?->name ?? $order->source_platform ?? 'In Store';

                fputcsv($handle, [
                    $order->created_at->format('Y-m-d H:i'),
                    $order->invoice_number ?? "#{$order->id}",
                    $order->customer?->full_name ?? 'Walk-in',
                    $order->customer?->leadSource?->name ?? '-',
                    $order->status,
                    $channelName,
                    $numItems,
                    $categories ?: '-',
                    $cost > 0 ? number_format($cost, 2) : '-',
                    $wholesaleValue > 0 ? number_format($wholesaleValue, 2) : '-',
                    number_format($order->sub_total ?? 0, 2),
                    number_format($serviceFee, 2),
                    number_format($profit, 2),
                    number_format($order->sales_tax ?? 0, 2),
                    number_format($order->shipping_cost ?? 0, 2),
                    number_format($order->total ?? 0, 2),
                    $paymentMethods,
                    '-',
                ]);

                $totalItems += $numItems;
                $totalCost += $cost;
                $totalWholesale += $wholesaleValue;
                $totalSubTotal += $order->sub_total ?? 0;
                $totalServiceFee += $serviceFee;
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
                number_format($totalServiceFee, 2),
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

        $startMonth = $request->input('start_month', now()->subMonths(12)->month);
        $startYear = $request->input('start_year', now()->subMonths(12)->year);
        $endMonth = $request->input('end_month', now()->month);
        $endYear = $request->input('end_year', now()->year);

        $startDate = Carbon::createFromDate($startYear, $startMonth, 1)->startOfMonth();
        $endDate = Carbon::createFromDate($endYear, $endMonth, 1)->endOfMonth();

        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $channels = $this->getSalesChannels($store->id);
        $monthlyData = $this->getMonthlyAggregatedData($store->id, $startDate, $endDate, $channels);

        $filename = 'sales-report-monthly-'.$startDate->format('Y-m').'-to-'.$endDate->format('Y-m').'.csv';

        return response()->streamDownload(function () use ($monthlyData, $channels) {
            $handle = fopen('php://output', 'w');

            // Build header row with dynamic channel columns
            $headers = [
                'Date',
                'Sales #',
                'Items Sold',
                'Total Cost',
                'Total Wholesale Value',
                'Total Sales Price',
                'Service Fee',
                'Tax',
                'Shipping',
            ];

            // Add channel columns
            foreach ($channels as $channel) {
                $headers[] = $channel['name'];
            }

            $headers[] = 'Total Paid';
            $headers[] = 'Gross Profit';
            $headers[] = 'Profit %';

            fputcsv($handle, $headers);

            foreach ($monthlyData as $row) {
                $rowData = [
                    $row['date'],
                    $row['sales_count'],
                    $row['items_sold'],
                    number_format($row['total_cost'], 2),
                    number_format($row['total_wholesale_value'], 2),
                    number_format($row['total_sales_price'], 2),
                    number_format($row['total_service_fee'] ?? 0, 2),
                    number_format($row['total_tax'] ?? 0, 2),
                    number_format($row['total_shipping'] ?? 0, 2),
                ];

                // Add channel values
                foreach ($channels as $channel) {
                    $key = 'total_'.$channel['code'];
                    $rowData[] = number_format($row[$key] ?? 0, 2);
                }

                $rowData[] = number_format($row['total_paid'], 2);
                $rowData[] = number_format($row['gross_profit'], 2);
                $rowData[] = number_format($row['profit_percent'], 2).'%';

                fputcsv($handle, $rowData);
            }

            // Totals row
            $totals = [
                'sales_count' => $monthlyData->sum('sales_count'),
                'items_sold' => $monthlyData->sum('items_sold'),
                'total_cost' => $monthlyData->sum('total_cost'),
                'total_wholesale_value' => $monthlyData->sum('total_wholesale_value'),
                'total_sales_price' => $monthlyData->sum('total_sales_price'),
                'total_service_fee' => $monthlyData->sum('total_service_fee'),
                'total_tax' => $monthlyData->sum('total_tax'),
                'total_shipping' => $monthlyData->sum('total_shipping'),
                'total_paid' => $monthlyData->sum('total_paid'),
                'gross_profit' => $monthlyData->sum('gross_profit'),
            ];

            $profitPercent = $totals['total_sales_price'] > 0
                ? ($totals['gross_profit'] / $totals['total_sales_price']) * 100
                : 0;

            $totalsRow = [
                'TOTALS',
                $totals['sales_count'],
                $totals['items_sold'],
                number_format($totals['total_cost'], 2),
                number_format($totals['total_wholesale_value'], 2),
                number_format($totals['total_sales_price'], 2),
                number_format($totals['total_service_fee'], 2),
                number_format($totals['total_tax'], 2),
                number_format($totals['total_shipping'], 2),
            ];

            // Add channel totals
            foreach ($channels as $channel) {
                $key = 'total_'.$channel['code'];
                $totalsRow[] = number_format($monthlyData->sum($key), 2);
            }

            $totalsRow[] = number_format($totals['total_paid'], 2);
            $totalsRow[] = number_format($totals['gross_profit'], 2);
            $totalsRow[] = number_format($profitPercent, 2).'%';

            fputcsv($handle, $totalsRow);

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

        $startDate = $request->filled('start_date')
            ? Carbon::parse($request->input('start_date'))->startOfDay()
            : now()->startOfMonth();

        $endDate = $request->filled('end_date')
            ? Carbon::parse($request->input('end_date'))->endOfDay()
            : now()->endOfDay();

        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $channels = $this->getSalesChannels($store->id);
        $dailyData = $this->getDailyAggregatedData($store->id, $startDate, $endDate, $channels);

        $filename = 'sales-report-mtd-'.$startDate->format('Y-m-d').'-to-'.$endDate->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($dailyData, $channels) {
            $handle = fopen('php://output', 'w');

            // Build header row with dynamic channel columns
            $headers = [
                'Date',
                'Sales #',
                'Items Sold',
                'Total Cost',
                'Total Wholesale Value',
                'Total Sales Price',
                'Service Fee',
                'Tax',
                'Shipping',
            ];

            // Add channel columns
            foreach ($channels as $channel) {
                $headers[] = $channel['name'];
            }

            $headers[] = 'Total Paid';
            $headers[] = 'Gross Profit';
            $headers[] = 'Profit %';

            fputcsv($handle, $headers);

            foreach ($dailyData as $row) {
                $rowData = [
                    $row['date'],
                    $row['sales_count'],
                    $row['items_sold'],
                    number_format($row['total_cost'], 2),
                    number_format($row['total_wholesale_value'], 2),
                    number_format($row['total_sales_price'], 2),
                    number_format($row['total_service_fee'] ?? 0, 2),
                    number_format($row['total_tax'] ?? 0, 2),
                    number_format($row['total_shipping'] ?? 0, 2),
                ];

                // Add channel values
                foreach ($channels as $channel) {
                    $key = 'total_'.$channel['code'];
                    $rowData[] = number_format($row[$key] ?? 0, 2);
                }

                $rowData[] = number_format($row['total_paid'], 2);
                $rowData[] = number_format($row['gross_profit'], 2);
                $rowData[] = number_format($row['profit_percent'], 2).'%';

                fputcsv($handle, $rowData);
            }

            // Totals row
            $totals = [
                'sales_count' => $dailyData->sum('sales_count'),
                'items_sold' => $dailyData->sum('items_sold'),
                'total_cost' => $dailyData->sum('total_cost'),
                'total_wholesale_value' => $dailyData->sum('total_wholesale_value'),
                'total_sales_price' => $dailyData->sum('total_sales_price'),
                'total_service_fee' => $dailyData->sum('total_service_fee'),
                'total_tax' => $dailyData->sum('total_tax'),
                'total_shipping' => $dailyData->sum('total_shipping'),
                'total_paid' => $dailyData->sum('total_paid'),
                'gross_profit' => $dailyData->sum('gross_profit'),
            ];

            $profitPercent = $totals['total_sales_price'] > 0
                ? ($totals['gross_profit'] / $totals['total_sales_price']) * 100
                : 0;

            $totalsRow = [
                'TOTALS',
                $totals['sales_count'],
                $totals['items_sold'],
                number_format($totals['total_cost'], 2),
                number_format($totals['total_wholesale_value'], 2),
                number_format($totals['total_sales_price'], 2),
                number_format($totals['total_service_fee'], 2),
                number_format($totals['total_tax'], 2),
                number_format($totals['total_shipping'], 2),
            ];

            // Add channel totals
            foreach ($channels as $channel) {
                $key = 'total_'.$channel['code'];
                $totalsRow[] = number_format($dailyData->sum($key), 2);
            }

            $totalsRow[] = number_format($totals['total_paid'], 2);
            $totalsRow[] = number_format($totals['gross_profit'], 2);
            $totalsRow[] = number_format($profitPercent, 2).'%';

            fputcsv($handle, $totalsRow);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Get sales channels for the store with fallback for legacy source_platform values.
     * Only includes active channels that are either local or linked to connected selling platforms.
     */
    protected function getSalesChannels(int $storeId): Collection
    {
        // Get configured sales channels, filtering out apps and non-connected platforms
        $channels = SalesChannel::where('store_id', $storeId)
            ->active()
            ->with('storeMarketplace')
            ->ordered()
            ->get()
            ->filter(function (SalesChannel $channel) {
                // Local channels are always included
                if ($channel->is_local) {
                    return true;
                }

                // Non-local channels must be linked to a marketplace
                if (! $channel->store_marketplace_id || ! $channel->storeMarketplace) {
                    return false;
                }

                // For marketplace-linked channels, only include if:
                // - It's not an app (is_app = false)
                // - It's connected successfully
                $marketplace = $channel->storeMarketplace;

                return ! $marketplace->is_app && $marketplace->connected_successfully;
            })
            ->map(fn (SalesChannel $channel) => [
                'id' => $channel->id,
                'name' => $channel->name,
                'code' => $channel->code,
                'type' => $channel->type,
                'is_local' => $channel->is_local,
                'color' => $channel->color,
            ]);

        // If no channels configured, provide defaults based on legacy source_platform values
        if ($channels->isEmpty()) {
            // Check what source_platforms exist in orders for this store
            $existingPlatforms = Order::where('store_id', $storeId)
                ->whereNotNull('source_platform')
                ->distinct()
                ->pluck('source_platform')
                ->filter();

            $defaultChannels = collect();

            // Always add a local channel
            $defaultChannels->push([
                'id' => null,
                'name' => 'In Store',
                'code' => 'in_store',
                'type' => 'local',
                'is_local' => true,
                'color' => null,
            ]);

            // Add channels for existing platforms
            foreach ($existingPlatforms as $platform) {
                if (in_array($platform, ['in_store', 'memo', 'repair', 'layaway'])) {
                    continue; // Skip local-type platforms
                }

                $defaultChannels->push([
                    'id' => null,
                    'name' => ucfirst($platform),
                    'code' => $platform,
                    'type' => $platform,
                    'is_local' => false,
                    'color' => null,
                ]);
            }

            return $defaultChannels;
        }

        return $channels;
    }

    /**
     * Get the channel code for an order.
     * Priority:
     * 1. Direct sales_channel_id relationship
     * 2. PlatformOrder → StoreMarketplace → SalesChannel (via store_marketplace_id)
     * 3. source_platform string (legacy)
     */
    protected function getOrderChannelCode(Order $order, Collection $channels): string
    {
        // Priority 1: If order has a direct sales channel relationship, use it
        if ($order->sales_channel_id && $order->salesChannel) {
            return $order->salesChannel->code;
        }

        // Priority 2: Check platform_order → store_marketplace → sales_channel
        if ($order->relationLoaded('platformOrder') && $order->platformOrder) {
            $marketplaceId = $order->platformOrder->store_marketplace_id;
            if ($marketplaceId) {
                // Find a sales channel linked to this marketplace
                $channelForMarketplace = SalesChannel::where('store_id', $order->store_id)
                    ->where('store_marketplace_id', $marketplaceId)
                    ->first();

                if ($channelForMarketplace) {
                    return $channelForMarketplace->code;
                }
            }
        }

        // Priority 3: Fall back to source_platform
        $platform = strtolower($order->source_platform ?? '');

        // Map legacy values to standard codes
        $localPlatforms = ['in_store', 'reb', 'memo', 'repair', 'layaway', ''];
        if (in_array($platform, $localPlatforms)) {
            // Find the local channel
            $localChannel = $channels->firstWhere('is_local', true);

            return $localChannel['code'] ?? 'in_store';
        }

        // Return the platform as-is if it exists in channels
        $matchingChannel = $channels->firstWhere('code', $platform);
        if ($matchingChannel) {
            return $matchingChannel['code'];
        }

        // Default to in_store if no match
        return 'in_store';
    }

    /**
     * Get monthly aggregated data.
     */
    protected function getMonthlyAggregatedData(int $storeId, Carbon $startDate, Carbon $endDate, Collection $channels, ?array $categoryIds = null)
    {
        $ordersQuery = Order::query()
            ->where('store_id', $storeId)
            ->whereIn('status', Order::PAID_STATUSES)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with([
                'items.variant',
                'items.product',
                'salesChannel',
                'platformOrder',
                'payments' => fn ($q) => $q->where('status', Payment::STATUS_COMPLETED),
            ]);

        // Apply category filter if set
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
            $totalServiceFee = 0;
            $totalTax = 0;
            $totalShipping = 0;

            // Initialize channel totals
            $channelTotals = [];
            foreach ($channels as $channel) {
                $channelTotals[$channel['code']] = 0;
            }

            foreach ($monthOrders as $order) {
                foreach ($order->items as $item) {
                    // Use stored wholesale_value, fallback to variant for historical data
                    $wholesalePrice = $item->wholesale_value ?? $item->variant?->wholesale_price ?? 0;
                    $costOfItem = $item->cost ?? $item->variant?->cost ?? 0;

                    // Wholesale value = sum of wholesale values
                    $totalWholesaleValue += $wholesalePrice * $item->quantity;

                    // Cost for profit calculation: use wholesale_value if exists, else cost
                    $effectiveCostForProfit = $wholesalePrice > 0 ? $wholesalePrice : $costOfItem;
                    $totalCost += $effectiveCostForProfit * $item->quantity;

                    $itemsSold += $item->quantity;
                }

                // Add service fee
                $totalServiceFee += (float) ($order->service_fee_value ?? 0);

                // Add tax
                $totalTax += (float) ($order->sales_tax ?? 0);

                // Add shipping
                $totalShipping += (float) ($order->shipping_cost ?? 0);

                // Add to appropriate channel total
                $channelCode = $this->getOrderChannelCode($order, $channels);
                if (isset($channelTotals[$channelCode])) {
                    $channelTotals[$channelCode] += $order->total ?? 0;
                }
            }

            $totalSalesPrice = $monthOrders->sum('sub_total');
            $totalPaid = $monthOrders->sum(fn ($o) => $o->payments->sum('amount'));
            // Profit = subtotal + service_fee - (wholesale_price if exists, else cost_of_item)
            $grossProfit = $totalSalesPrice + $totalServiceFee - $totalCost;
            $profitPercent = $totalSalesPrice > 0 ? ($grossProfit / $totalSalesPrice) * 100 : 0;

            $monthData = [
                'date' => $current->format('M Y'),
                'start_date' => $current->copy()->startOfMonth()->format('Y-m-d'),
                'end_date' => $current->copy()->endOfMonth()->format('Y-m-d'),
                'sales_count' => $monthOrders->count(),
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

            // Add channel totals with prefixed keys
            foreach ($channelTotals as $code => $total) {
                $monthData['total_'.$code] = $total;
            }

            $months->push($monthData);

            $current->addMonth();
        }

        return $months->reverse()->values();
    }

    /**
     * Get daily aggregated data.
     */
    protected function getDailyAggregatedData(int $storeId, Carbon $startDate, Carbon $endDate, Collection $channels, ?array $categoryIds = null)
    {
        $ordersQuery = Order::query()
            ->where('store_id', $storeId)
            ->whereIn('status', Order::PAID_STATUSES)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with([
                'items.variant',
                'items.product',
                'salesChannel',
                'platformOrder',
                'payments' => fn ($q) => $q->where('status', Payment::STATUS_COMPLETED),
            ]);

        // Apply category filter if set
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
            $totalServiceFee = 0;
            $totalTax = 0;
            $totalShipping = 0;

            // Initialize channel totals
            $channelTotals = [];
            foreach ($channels as $channel) {
                $channelTotals[$channel['code']] = 0;
            }

            foreach ($dayOrders as $order) {
                foreach ($order->items as $item) {
                    // Use stored wholesale_value, fallback to variant for historical data
                    $wholesalePrice = $item->wholesale_value ?? $item->variant?->wholesale_price ?? 0;
                    $costOfItem = $item->cost ?? $item->variant?->cost ?? 0;

                    // Wholesale value = sum of wholesale values
                    $totalWholesaleValue += $wholesalePrice * $item->quantity;

                    // Cost for profit calculation: use wholesale_value if exists, else cost
                    $effectiveCostForProfit = $wholesalePrice > 0 ? $wholesalePrice : $costOfItem;
                    $totalCost += $effectiveCostForProfit * $item->quantity;

                    $itemsSold += $item->quantity;
                }

                // Add service fee
                $totalServiceFee += (float) ($order->service_fee_value ?? 0);

                // Add tax
                $totalTax += (float) ($order->sales_tax ?? 0);

                // Add shipping
                $totalShipping += (float) ($order->shipping_cost ?? 0);

                // Add to appropriate channel total
                $channelCode = $this->getOrderChannelCode($order, $channels);
                if (isset($channelTotals[$channelCode])) {
                    $channelTotals[$channelCode] += $order->total ?? 0;
                }
            }

            $totalSalesPrice = $dayOrders->sum('sub_total');
            $totalPaid = $dayOrders->sum(fn ($o) => $o->payments->sum('amount'));
            // Profit = subtotal + service_fee - (wholesale_price if exists, else cost_of_item)
            $grossProfit = $totalSalesPrice + $totalServiceFee - $totalCost;
            $profitPercent = $totalSalesPrice > 0 ? ($grossProfit / $totalSalesPrice) * 100 : 0;

            $dayData = [
                'date' => $current->format('M d, Y'),
                'date_key' => $current->format('Y-m-d'),
                'sales_count' => $dayOrders->count(),
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

            // Add channel totals with prefixed keys
            foreach ($channelTotals as $code => $total) {
                $dayData['total_'.$code] = $total;
            }

            $days->push($dayData);

            $current->addDay();
        }

        return $days->reverse()->values();
    }

    /**
     * Get categories for the store with tree structure.
     */
    protected function getCategories(int $storeId): array
    {
        $categories = Category::where('store_id', $storeId)
            ->get(['id', 'name', 'parent_id']);

        return $this->buildCategoryTree($categories);
    }

    /**
     * Build a flat list of categories in tree order with depth information.
     */
    protected function buildCategoryTree(Collection $categories, ?int $parentId = null, int $depth = 0): array
    {
        $result = [];

        // Find all category IDs that have children
        $parentIds = $categories->whereNotNull('parent_id')->pluck('parent_id')->unique()->toArray();

        $children = $categories
            ->filter(fn ($c) => $c->parent_id == $parentId)
            ->sortBy('name');

        foreach ($children as $category) {
            $hasChildren = in_array($category->id, $parentIds);
            $result[] = [
                'value' => $category->id,
                'label' => $category->name,
                'depth' => $depth,
                'isLeaf' => ! $hasChildren,
            ];

            // Recursively add children
            $result = array_merge($result, $this->buildCategoryTree($categories, $category->id, $depth + 1));
        }

        return $result;
    }

    /**
     * Get all descendant category IDs for a given category.
     *
     * @return array<int>
     */
    protected function getCategoryDescendantIds(int $categoryId, int $storeId): array
    {
        $allIds = [$categoryId];

        $childIds = Category::where('store_id', $storeId)
            ->where('parent_id', $categoryId)
            ->pluck('id')
            ->toArray();

        foreach ($childIds as $childId) {
            $allIds = array_merge($allIds, $this->getCategoryDescendantIds($childId, $storeId));
        }

        return $allIds;
    }

    /**
     * Get category breakdown for orders.
     * Returns sales aggregated by each leaf category.
     */
    protected function getCategoryBreakdown(Collection $orders, int $storeId): array
    {
        $categories = Category::where('store_id', $storeId)
            ->get(['id', 'name', 'parent_id'])
            ->keyBy('id');

        // Find all category IDs that have children (non-leaf)
        $parentIds = $categories->whereNotNull('parent_id')->pluck('parent_id')->unique()->toArray();

        // Build breakdown by category
        $breakdown = [];

        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $categoryId = $item->category_id ?? $item->product?->category_id;
                if (! $categoryId) {
                    $categoryId = 0; // Uncategorized
                }

                if (! isset($breakdown[$categoryId])) {
                    $category = $categories->get($categoryId);
                    $breakdown[$categoryId] = [
                        'category_id' => $categoryId,
                        'category_name' => $category?->name ?? 'Uncategorized',
                        'is_leaf' => $categoryId === 0 || ! in_array($categoryId, $parentIds),
                        'parent_id' => $category?->parent_id,
                        'items_sold' => 0,
                        'orders_count' => 0,
                        'order_ids' => [],
                        'total_cost' => 0,
                        'total_wholesale' => 0,
                        'total_sales' => 0,
                        'total_profit' => 0,
                    ];
                }

                $wholesalePrice = $item->wholesale_value ?? $item->variant?->wholesale_price ?? 0;
                $costOfItem = $item->cost ?? $item->variant?->cost ?? 0;
                $effectiveCost = $wholesalePrice > 0 ? $wholesalePrice : $costOfItem;

                $itemTotal = ($item->price ?? $item->unit_price ?? 0) * ($item->quantity ?? 1);
                $itemCost = $effectiveCost * ($item->quantity ?? 1);

                $breakdown[$categoryId]['items_sold'] += $item->quantity ?? 1;
                $breakdown[$categoryId]['total_cost'] += $itemCost;
                $breakdown[$categoryId]['total_wholesale'] += $wholesalePrice * ($item->quantity ?? 1);
                $breakdown[$categoryId]['total_sales'] += $itemTotal;
                $breakdown[$categoryId]['total_profit'] += $itemTotal - $itemCost;

                // Track unique orders
                if (! in_array($order->id, $breakdown[$categoryId]['order_ids'])) {
                    $breakdown[$categoryId]['order_ids'][] = $order->id;
                    $breakdown[$categoryId]['orders_count']++;
                }
            }
        }

        // Remove order_ids from final output (just used for counting)
        foreach ($breakdown as &$cat) {
            unset($cat['order_ids']);
        }

        // Sort by total sales descending
        usort($breakdown, fn ($a, $b) => $b['total_sales'] <=> $a['total_sales']);

        return array_values($breakdown);
    }
}
