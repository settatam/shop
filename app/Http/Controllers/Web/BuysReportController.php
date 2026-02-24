<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Status;
use App\Models\Transaction;
use App\Services\StoreContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BuysReportController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
    ) {}

    /**
     * Get the "Payment Processed" status ID for a store.
     */
    protected function getPaymentProcessedStatusId(int $storeId): ?int
    {
        return Status::where('store_id', $storeId)
            ->where('slug', 'payment_processed')
            ->value('id');
    }

    /**
     * Get categories for the store with tree structure.
     *
     * @return array<int, array{value: int, label: string, depth: int, isLeaf: bool}>
     */
    protected function getCategories(int $storeId): array
    {
        $categories = Category::where('store_id', $storeId)
            ->get(['id', 'name', 'parent_id']);

        return $this->buildCategoryTree($categories);
    }

    /**
     * Build a flat list of categories in tree order with depth information.
     *
     * @return array<int, array{value: int, label: string, depth: int, isLeaf: bool}>
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
     * Get category breakdown for transactions.
     * Returns buys aggregated by each category.
     *
     * @return array<int, array{category_id: int, category_name: string, is_leaf: bool, items_count: int, transactions_count: int, total_purchase: float, total_estimated_value: float, total_profit: float}>
     */
    protected function getCategoryBreakdown(Collection $transactions, int $storeId): array
    {
        $categories = Category::where('store_id', $storeId)
            ->get(['id', 'name', 'parent_id'])
            ->keyBy('id');

        // Find all category IDs that have children (non-leaf)
        $parentIds = $categories->whereNotNull('parent_id')->pluck('parent_id')->unique()->toArray();

        // Helper to find root ancestor of a category
        $rootCategoryCache = [];
        $findRootCategoryId = function (int $categoryId) use ($categories, &$rootCategoryCache, &$findRootCategoryId): ?int {
            if (isset($rootCategoryCache[$categoryId])) {
                return $rootCategoryCache[$categoryId];
            }
            $category = $categories->get($categoryId);
            if (! $category) {
                return null;
            }
            if (! $category->parent_id) {
                return $rootCategoryCache[$categoryId] = $categoryId;
            }

            return $rootCategoryCache[$categoryId] = $findRootCategoryId($category->parent_id);
        };

        // Build breakdown by category
        $breakdown = [];

        foreach ($transactions as $transaction) {
            foreach ($transaction->items as $item) {
                $categoryId = $item->category_id ?? 0; // 0 for uncategorized
                if (! $categoryId) {
                    $categoryId = 0;
                }

                if (! isset($breakdown[$categoryId])) {
                    $category = $categories->get($categoryId);
                    $breakdown[$categoryId] = [
                        'category_id' => $categoryId,
                        'category_name' => $category?->name ?? 'Uncategorized',
                        'is_leaf' => $categoryId === 0 || ! in_array($categoryId, $parentIds),
                        'parent_id' => $category?->parent_id,
                        'root_category_id' => $categoryId === 0 ? null : $findRootCategoryId($categoryId),
                        'items_count' => 0,
                        'transactions_count' => 0,
                        'transaction_ids' => [],
                        'total_purchase' => 0,
                        'total_estimated_value' => 0,
                        'total_profit' => 0,
                    ];
                }

                $itemPrice = (float) ($item->price ?? 0); // Estimated value
                $itemBuyPrice = (float) ($item->buy_price ?? 0); // What we paid

                $breakdown[$categoryId]['items_count'] += $item->quantity ?? 1;
                $breakdown[$categoryId]['total_estimated_value'] += $itemPrice * ($item->quantity ?? 1);
                $breakdown[$categoryId]['total_purchase'] += $itemBuyPrice * ($item->quantity ?? 1);

                // Track unique transactions
                if (! in_array($transaction->id, $breakdown[$categoryId]['transaction_ids'])) {
                    $breakdown[$categoryId]['transaction_ids'][] = $transaction->id;
                    $breakdown[$categoryId]['transactions_count']++;
                }
            }
        }

        // Calculate profit and remove transaction_ids from final output
        foreach ($breakdown as &$cat) {
            $cat['total_profit'] = $cat['total_estimated_value'] - $cat['total_purchase'];
            unset($cat['transaction_ids']);
        }

        // Sort by total estimated value descending
        usort($breakdown, fn ($a, $b) => $b['total_estimated_value'] <=> $a['total_estimated_value']);

        return array_values($breakdown);
    }

    /**
     * Unified Buys Report - Daily breakdown with date range.
     * Shows ALL completed buys regardless of source (online, in-store, trade-in).
     */
    public function index(Request $request): Response
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

        $dailyData = $this->getAllBuysDailyData($store->id, $startDate, $endDate, $categoryIds);

        $totals = $this->calculateTotals($dailyData);

        // Get transactions for category breakdown
        $paymentProcessedStatusId = $this->getPaymentProcessedStatusId($store->id);
        $transactionsQuery = Transaction::query()
            ->where('store_id', $store->id)
            ->where('status_id', $paymentProcessedStatusId)
            ->whereNotNull('payment_processed_at')
            ->whereBetween('payment_processed_at', [$startDate, $endDate])
            ->with(['items.category']);

        if ($categoryIds) {
            $transactionsQuery->whereHas('items', fn ($q) => $q->whereIn('category_id', $categoryIds));
        }

        $categoryBreakdown = $this->getCategoryBreakdown($transactionsQuery->get(), $store->id);

        return Inertia::render('reports/buys/Index', [
            'dailyData' => $dailyData,
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
        if ($startDate->isSameMonth($endDate)) {
            return $startDate->format('F Y');
        }

        return $startDate->format('M d, Y').' - '.$endDate->format('M d, Y');
    }

    /**
     * Unified Buys Report - Daily view showing individual transactions.
     */
    public function daily(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();

        // Parse date range from request, default to today
        $startDate = $request->filled('start_date')
            ? Carbon::parse($request->input('start_date'))->startOfDay()
            : now()->startOfDay();

        $endDate = $request->filled('end_date')
            ? Carbon::parse($request->input('end_date'))->endOfDay()
            : now()->endOfDay();

        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        // Category filter - get descendant IDs if filtering
        $categoryIds = null;
        if ($request->filled('category_id')) {
            $categoryIds = $this->getCategoryDescendantIds((int) $request->category_id, $store->id);
        }

        $paymentProcessedStatusId = $this->getPaymentProcessedStatusId($store->id);

        $transactionsQuery = Transaction::query()
            ->where('store_id', $store->id)
            ->where('status_id', $paymentProcessedStatusId)
            ->whereNotNull('payment_processed_at')
            ->whereBetween('payment_processed_at', [$startDate, $endDate])
            ->with(['items.category', 'customer', 'user']);

        // Apply category filter if set
        if ($categoryIds) {
            $transactionsQuery->whereHas('items', fn ($q) => $q->whereIn('category_id', $categoryIds));
        }

        $transactionsRaw = $transactionsQuery->orderBy('payment_processed_at', 'desc')->get();

        // Get category breakdown
        $categoryBreakdown = $this->getCategoryBreakdown($transactionsRaw, $store->id);

        $transactions = $transactionsRaw
            ->map(function ($transaction) {
                $estimatedValue = $transaction->items->sum('price');
                $profit = $estimatedValue - ($transaction->final_offer ?? 0);

                // Get categories from items
                $categories = $transaction->items
                    ->pluck('category.name')
                    ->filter()
                    ->unique()
                    ->values()
                    ->implode(', ');

                return [
                    'id' => $transaction->id,
                    'date' => Carbon::parse($transaction->payment_processed_at)->format('Y-m-d H:i'),
                    'transaction_number' => $transaction->transaction_number ?? "#{$transaction->id}",
                    'customer' => $transaction->customer?->full_name ?? 'Walk-in',
                    'type' => ucfirst($transaction->type ?? 'in_store'),
                    'source' => ucfirst($transaction->source ?? 'direct'),
                    'categories' => $categories ?: '-',
                    'num_items' => $transaction->items->count(),
                    'purchase_amt' => $transaction->final_offer ?? 0,
                    'estimated_value' => $estimatedValue,
                    'profit' => $profit,
                    'profit_percent' => ($transaction->final_offer ?? 0) > 0
                        ? ($profit / ($transaction->final_offer ?? 1)) * 100
                        : 0,
                    'user' => $transaction->user?->name ?? '-',
                ];
            });

        // Calculate totals
        $totals = [
            'num_items' => $transactions->sum('num_items'),
            'purchase_amt' => $transactions->sum('purchase_amt'),
            'estimated_value' => $transactions->sum('estimated_value'),
            'profit' => $transactions->sum('profit'),
            'profit_percent' => $transactions->sum('purchase_amt') > 0
                ? ($transactions->sum('profit') / $transactions->sum('purchase_amt')) * 100
                : 0,
        ];

        return Inertia::render('reports/buys/Daily', [
            'transactions' => $transactions,
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
     * Export daily transactions to CSV.
     */
    public function exportDaily(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();

        $startDate = $request->filled('start_date')
            ? Carbon::parse($request->input('start_date'))->startOfDay()
            : now()->startOfDay();

        $endDate = $request->filled('end_date')
            ? Carbon::parse($request->input('end_date'))->endOfDay()
            : now()->endOfDay();

        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $paymentProcessedStatusId = $this->getPaymentProcessedStatusId($store->id);

        $transactions = Transaction::query()
            ->where('store_id', $store->id)
            ->where('status_id', $paymentProcessedStatusId)
            ->whereNotNull('payment_processed_at')
            ->whereBetween('payment_processed_at', [$startDate, $endDate])
            ->with(['items', 'customer', 'user'])
            ->orderBy('payment_processed_at', 'desc')
            ->get();

        $filename = 'buys-daily-'.$startDate->format('Y-m-d').'-to-'.$endDate->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($transactions) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Date',
                'Transaction #',
                'Customer',
                'Type',
                'Source',
                '# Items',
                'Purchase Amt',
                'Estimated Value',
                'Profit',
                'Profit %',
                'User',
            ]);

            $totalItems = 0;
            $totalPurchase = 0;
            $totalEstimated = 0;
            $totalProfit = 0;

            foreach ($transactions as $transaction) {
                $estimatedValue = $transaction->items->sum('price');
                $purchaseAmt = $transaction->final_offer ?? 0;
                $profit = $estimatedValue - $purchaseAmt;
                $profitPercent = $purchaseAmt > 0 ? ($profit / $purchaseAmt) * 100 : 0;
                $numItems = $transaction->items->count();

                fputcsv($handle, [
                    Carbon::parse($transaction->payment_processed_at)->format('Y-m-d H:i'),
                    $transaction->transaction_number ?? "#{$transaction->id}",
                    $transaction->customer?->full_name ?? 'Walk-in',
                    ucfirst($transaction->type ?? 'in_store'),
                    ucfirst($transaction->source ?? 'direct'),
                    $numItems,
                    number_format($purchaseAmt, 2),
                    number_format($estimatedValue, 2),
                    number_format($profit, 2),
                    number_format($profitPercent, 2).'%',
                    $transaction->user?->name ?? '-',
                ]);

                $totalItems += $numItems;
                $totalPurchase += $purchaseAmt;
                $totalEstimated += $estimatedValue;
                $totalProfit += $profit;
            }

            $totalProfitPercent = $totalPurchase > 0 ? ($totalProfit / $totalPurchase) * 100 : 0;

            fputcsv($handle, [
                'TOTALS',
                '',
                '',
                '',
                '',
                $totalItems,
                number_format($totalPurchase, 2),
                number_format($totalEstimated, 2),
                number_format($totalProfit, 2),
                number_format($totalProfitPercent, 2).'%',
                '',
            ]);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Unified Buys Report - Month over Month.
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

        $monthlyData = $this->getAllBuysMonthlyData($store->id, $startDate, $endDate, $categoryIds);

        $totals = $this->calculateTotals($monthlyData);

        // Get transactions for category breakdown
        $paymentProcessedStatusId = $this->getPaymentProcessedStatusId($store->id);
        $transactionsQuery = Transaction::query()
            ->where('store_id', $store->id)
            ->where('status_id', $paymentProcessedStatusId)
            ->whereNotNull('payment_processed_at')
            ->whereBetween('payment_processed_at', [$startDate, $endDate])
            ->with(['items.category']);

        if ($categoryIds) {
            $transactionsQuery->whereHas('items', fn ($q) => $q->whereIn('category_id', $categoryIds));
        }

        $categoryBreakdown = $this->getCategoryBreakdown($transactionsQuery->get(), $store->id);

        return Inertia::render('reports/buys/Monthly', [
            'monthlyData' => $monthlyData,
            'totals' => $totals,
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
     * Unified Buys Report - Year over Year.
     */
    public function yearly(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();

        // Category filter - get descendant IDs if filtering
        $categoryIds = null;
        if ($request->filled('category_id')) {
            $categoryIds = $this->getCategoryDescendantIds((int) $request->category_id, $store->id);
        }

        $yearlyData = $this->getAllBuysYearlyData($store->id, $categoryIds);
        $totals = $this->calculateTotals($yearlyData);

        // Get transactions for category breakdown (last 5 years)
        $paymentProcessedStatusId = $this->getPaymentProcessedStatusId($store->id);
        $startDate = now()->subYears(4)->startOfYear();
        $endDate = now()->endOfYear();

        $transactionsQuery = Transaction::query()
            ->where('store_id', $store->id)
            ->where('status_id', $paymentProcessedStatusId)
            ->whereNotNull('payment_processed_at')
            ->whereBetween('payment_processed_at', [$startDate, $endDate])
            ->with(['items.category']);

        if ($categoryIds) {
            $transactionsQuery->whereHas('items', fn ($q) => $q->whereIn('category_id', $categoryIds));
        }

        $categoryBreakdown = $this->getCategoryBreakdown($transactionsQuery->get(), $store->id);

        return Inertia::render('reports/buys/Yearly', [
            'yearlyData' => $yearlyData,
            'totals' => $totals,
            'categories' => $this->getCategories($store->id),
            'categoryBreakdown' => $categoryBreakdown,
            'filters' => $request->only(['category_id']),
        ]);
    }

    /**
     * Export Unified daily report to CSV.
     */
    public function exportIndex(Request $request): StreamedResponse
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

        $dailyData = $this->getAllBuysDailyData($store->id, $startDate, $endDate);

        $filename = 'buys-'.$startDate->format('Y-m-d').'-to-'.$endDate->format('Y-m-d').'.csv';

        return $this->exportToCsv($dailyData, $filename);
    }

    /**
     * Export Unified Monthly to CSV.
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

        $monthlyData = $this->getAllBuysMonthlyData($store->id, $startDate, $endDate);

        $filename = 'buys-monthly-'.$startDate->format('Y-m').'-to-'.$endDate->format('Y-m').'.csv';

        return $this->exportToCsv($monthlyData, $filename);
    }

    /**
     * Export Monthly Category Breakdown to CSV.
     */
    public function exportMonthlyCategories(Request $request): StreamedResponse
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

        $categoryIds = null;
        if ($request->filled('category_id')) {
            $categoryIds = $this->getCategoryDescendantIds((int) $request->category_id, $store->id);
        }

        $paymentProcessedStatusId = $this->getPaymentProcessedStatusId($store->id);

        $transactionsQuery = Transaction::with('items')
            ->where('store_id', $store->id)
            ->where('status_id', $paymentProcessedStatusId)
            ->whereBetween('payment_processed_at', [$startDate, $endDate]);

        if ($categoryIds) {
            $transactionsQuery->whereHas('items', fn ($q) => $q->whereIn('category_id', $categoryIds));
        }

        $categoryBreakdown = $this->getCategoryBreakdown($transactionsQuery->get(), $store->id);

        $filename = 'buys-by-category-'.$startDate->format('Y-m').'-to-'.$endDate->format('Y-m').'.csv';

        return $this->exportCategoryBreakdownToCsv($categoryBreakdown, $filename);
    }

    /**
     * Email Monthly Report.
     */
    public function emailMonthly(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'emails' => 'required|array|min:1',
            'emails.*' => 'required|email',
            'subject' => 'nullable|string|max:255',
        ]);

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

        $monthlyData = $this->getAllBuysMonthlyData($store->id, $startDate, $endDate);
        $monthlyCollection = collect($monthlyData);

        $headers = ['Month', '# of Buys', 'Purchase Amt', 'Estimated Value', 'Profit', 'Profit %', 'Avg Buy Price'];
        $rows = $monthlyCollection->map(fn ($row) => [
            $row['date'],
            $row['buys_count'],
            '$'.number_format($row['purchase_amt'], 2),
            '$'.number_format($row['estimated_value'], 2),
            '$'.number_format($row['profit'], 2),
            number_format($row['profit_percent'], 1).'%',
            '$'.number_format($row['avg_buy_price'], 2),
        ])->toArray();

        // Calculate totals
        $totalBuys = $monthlyCollection->sum('buys_count');
        $totalPurchase = $monthlyCollection->sum('purchase_amt');
        $totalEstimated = $monthlyCollection->sum('estimated_value');
        $totalProfit = $monthlyCollection->sum('profit');
        $avgProfitPercent = $totalEstimated > 0 ? ($totalProfit / $totalEstimated) * 100 : 0;
        $avgBuyPrice = $totalBuys > 0 ? $totalPurchase / $totalBuys : 0;

        // Add totals row
        $rows[] = [
            'TOTALS',
            $totalBuys,
            '$'.number_format($totalPurchase, 2),
            '$'.number_format($totalEstimated, 2),
            '$'.number_format($totalProfit, 2),
            number_format($avgProfitPercent, 1).'%',
            '$'.number_format($avgBuyPrice, 2),
        ];

        $subject = $request->input('subject', 'Monthly Buys Report');
        $description = "Buys data from {$startDate->format('M Y')} to {$endDate->format('M Y')}";

        $mailable = new \App\Mail\DynamicReportMail(
            $subject,
            $description,
            ['headers' => $headers, 'rows' => $rows],
            count($rows) - 1, // Exclude totals row from count
            now()
        );

        // Attach CSV
        $mailable->attachCsv($headers, $rows, 'monthly-buys-report.csv');

        // Set from address using store settings
        $fromAddress = $store->email_from_address ?: config('mail.from.address');
        $fromName = $store->email_from_name ?: config('mail.from.name', $store->name);
        $mailable->from($fromAddress, $fromName);

        if ($store->email_reply_to_address) {
            $mailable->replyTo($store->email_reply_to_address);
        }

        // Send to all recipients
        foreach ($request->input('emails') as $email) {
            \Illuminate\Support\Facades\Mail::to($email)->send(clone $mailable);
        }

        $count = count($request->input('emails'));

        return response()->json([
            'success' => true,
            'message' => $count === 1 ? 'Report sent successfully' : "Report sent to {$count} recipients",
        ]);
    }

    /**
     * Email Monthly Category Breakdown Report.
     */
    public function emailMonthlyCategories(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'emails' => 'required|array|min:1',
            'emails.*' => 'required|email',
            'subject' => 'nullable|string|max:255',
        ]);

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

        $categoryIds = null;
        if ($request->filled('category_id')) {
            $categoryIds = $this->getCategoryDescendantIds((int) $request->category_id, $store->id);
        }

        $paymentProcessedStatusId = $this->getPaymentProcessedStatusId($store->id);

        $transactionsQuery = Transaction::with('items')
            ->where('store_id', $store->id)
            ->where('status_id', $paymentProcessedStatusId)
            ->whereBetween('payment_processed_at', [$startDate, $endDate]);

        if ($categoryIds) {
            $transactionsQuery->whereHas('items', fn ($q) => $q->whereIn('category_id', $categoryIds));
        }

        $categoryBreakdown = $this->getCategoryBreakdown($transactionsQuery->get(), $store->id);
        $categoryCollection = collect($categoryBreakdown);

        $headers = ['Category', 'Transactions', 'Items', 'Purchase Amt', 'Est. Value', 'Profit'];
        $rows = $categoryCollection->map(fn ($row) => [
            $row['category_name'],
            $row['transactions_count'],
            $row['items_count'],
            '$'.number_format($row['total_purchase'], 2),
            '$'.number_format($row['total_estimated_value'], 2),
            '$'.number_format($row['total_profit'], 2),
        ])->toArray();

        // Calculate totals
        $totalTransactions = $categoryCollection->sum('transactions_count');
        $totalItems = $categoryCollection->sum('items_count');
        $totalPurchase = $categoryCollection->sum('total_purchase');
        $totalEstimated = $categoryCollection->sum('total_estimated_value');
        $totalProfit = $categoryCollection->sum('total_profit');

        // Add totals row
        $rows[] = [
            'TOTALS',
            $totalTransactions,
            $totalItems,
            '$'.number_format($totalPurchase, 2),
            '$'.number_format($totalEstimated, 2),
            '$'.number_format($totalProfit, 2),
        ];

        $subject = $request->input('subject', 'Buys by Category Report');
        $description = "Category breakdown from {$startDate->format('M Y')} to {$endDate->format('M Y')}";

        $mailable = new \App\Mail\DynamicReportMail(
            $subject,
            $description,
            ['headers' => $headers, 'rows' => $rows],
            count($rows) - 1, // Exclude totals row from count
            now()
        );

        // Attach CSV
        $mailable->attachCsv($headers, $rows, 'buys-by-category-report.csv');

        // Set from address using store settings
        $fromAddress = $store->email_from_address ?: config('mail.from.address');
        $fromName = $store->email_from_name ?: config('mail.from.name', $store->name);
        $mailable->from($fromAddress, $fromName);

        if ($store->email_reply_to_address) {
            $mailable->replyTo($store->email_reply_to_address);
        }

        // Send to all recipients
        foreach ($request->input('emails') as $email) {
            \Illuminate\Support\Facades\Mail::to($email)->send(clone $mailable);
        }

        $count = count($request->input('emails'));

        return response()->json([
            'success' => true,
            'message' => $count === 1 ? 'Report sent successfully' : "Report sent to {$count} recipients",
        ]);
    }

    /**
     * Export category breakdown to CSV.
     *
     * @param  array<int, array{category_id: int, category_name: string, is_leaf: bool, items_count: int, transactions_count: int, total_purchase: float, total_estimated_value: float, total_profit: float}>  $data
     */
    protected function exportCategoryBreakdownToCsv(array $data, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($data) {
            $handle = fopen('php://output', 'w');

            // Headers
            fputcsv($handle, ['Category', 'Transactions', 'Items', 'Purchase Amt', 'Est. Value', 'Profit']);

            // Data rows
            foreach ($data as $row) {
                fputcsv($handle, [
                    $row['category_name'],
                    $row['transactions_count'],
                    $row['items_count'],
                    $row['total_purchase'],
                    $row['total_estimated_value'],
                    $row['total_profit'],
                ]);
            }

            // Totals row
            $totals = [
                'TOTALS',
                array_sum(array_column($data, 'transactions_count')),
                array_sum(array_column($data, 'items_count')),
                array_sum(array_column($data, 'total_purchase')),
                array_sum(array_column($data, 'total_estimated_value')),
                array_sum(array_column($data, 'total_profit')),
            ];
            fputcsv($handle, $totals);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Export Unified Yearly to CSV.
     */
    public function exportYearly(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $yearlyData = $this->getAllBuysYearlyData($store->id);

        return $this->exportToCsv($yearlyData, 'buys-yearly-'.now()->format('Y-m-d').'.csv');
    }

    /**
     * Get all buys daily aggregated data (no type/source filter).
     *
     * @param  array<int>|null  $categoryIds
     */
    protected function getAllBuysDailyData(int $storeId, Carbon $startDate, Carbon $endDate, ?array $categoryIds = null)
    {
        return $this->getDailyBuysData($storeId, $startDate, $endDate, fn ($query) => $query, $categoryIds);
    }

    /**
     * Get all buys monthly aggregated data (no type/source filter).
     *
     * @param  array<int>|null  $categoryIds
     */
    protected function getAllBuysMonthlyData(int $storeId, Carbon $startDate, Carbon $endDate, ?array $categoryIds = null)
    {
        return $this->getMonthlyBuysData($storeId, $startDate, $endDate, fn ($query) => $query, $categoryIds);
    }

    /**
     * Get all buys yearly aggregated data (no type/source filter).
     *
     * @param  array<int>|null  $categoryIds
     */
    protected function getAllBuysYearlyData(int $storeId, ?array $categoryIds = null)
    {
        return $this->getYearlyBuysData($storeId, fn ($query) => $query, $categoryIds);
    }

    /**
     * In-Store Buys Report - Month to Date (daily breakdown).
     */
    public function inStore(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->startOfMonth();
        $endDate = now();

        $dailyData = $this->getInStoreDailyData($store->id, $startDate, $endDate);

        $totals = $this->calculateTotals($dailyData);

        return Inertia::render('reports/buys/InStore', [
            'dailyData' => $dailyData,
            'totals' => $totals,
            'month' => now()->format('F Y'),
        ]);
    }

    /**
     * In-Store Buys Report - Month over Month.
     */
    public function inStoreMonthly(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->subMonths(12)->startOfMonth();
        $endDate = now()->endOfMonth();

        $monthlyData = $this->getInStoreMonthlyData($store->id, $startDate, $endDate);

        $totals = $this->calculateTotals($monthlyData);

        return Inertia::render('reports/buys/InStoreMonthly', [
            'monthlyData' => $monthlyData,
            'totals' => $totals,
        ]);
    }

    /**
     * Online Buys Report - Month to Date (daily breakdown).
     */
    public function online(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->startOfMonth();
        $endDate = now();

        $dailyData = $this->getOnlineDailyData($store->id, $startDate, $endDate);

        $totals = $this->calculateTotals($dailyData);

        return Inertia::render('reports/buys/Online', [
            'dailyData' => $dailyData,
            'totals' => $totals,
            'month' => now()->format('F Y'),
        ]);
    }

    /**
     * Online Buys Report - Month over Month.
     */
    public function onlineMonthly(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->subMonths(12)->startOfMonth();
        $endDate = now()->endOfMonth();

        $monthlyData = $this->getOnlineMonthlyData($store->id, $startDate, $endDate);

        $totals = $this->calculateTotals($monthlyData);

        return Inertia::render('reports/buys/OnlineMonthly', [
            'monthlyData' => $monthlyData,
            'totals' => $totals,
        ]);
    }

    /**
     * Trade-In Buys Report - Month to Date (daily breakdown).
     */
    public function tradeIn(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->startOfMonth();
        $endDate = now();

        $dailyData = $this->getTradeInDailyData($store->id, $startDate, $endDate);

        $totals = $this->calculateTotals($dailyData);

        return Inertia::render('reports/buys/TradeIn', [
            'dailyData' => $dailyData,
            'totals' => $totals,
            'month' => now()->format('F Y'),
        ]);
    }

    /**
     * Trade-In Buys Report - Month over Month.
     */
    public function tradeInMonthly(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->subMonths(12)->startOfMonth();
        $endDate = now()->endOfMonth();

        $monthlyData = $this->getTradeInMonthlyData($store->id, $startDate, $endDate);

        $totals = $this->calculateTotals($monthlyData);

        return Inertia::render('reports/buys/TradeInMonthly', [
            'monthlyData' => $monthlyData,
            'totals' => $totals,
        ]);
    }

    /**
     * Export In-Store MTD to CSV.
     */
    public function exportInStore(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->startOfMonth();
        $endDate = now();

        $dailyData = $this->getInStoreDailyData($store->id, $startDate, $endDate);

        return $this->exportToCsv($dailyData, 'buys-in-store-mtd-'.now()->format('Y-m-d').'.csv');
    }

    /**
     * Export In-Store Monthly to CSV.
     */
    public function exportInStoreMonthly(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->subMonths(12)->startOfMonth();
        $endDate = now()->endOfMonth();

        $monthlyData = $this->getInStoreMonthlyData($store->id, $startDate, $endDate);

        return $this->exportToCsv($monthlyData, 'buys-in-store-monthly-'.now()->format('Y-m-d').'.csv');
    }

    /**
     * Export Online MTD to CSV.
     */
    public function exportOnline(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->startOfMonth();
        $endDate = now();

        $dailyData = $this->getOnlineDailyData($store->id, $startDate, $endDate);

        return $this->exportToCsv($dailyData, 'buys-online-mtd-'.now()->format('Y-m-d').'.csv');
    }

    /**
     * Export Online Monthly to CSV.
     */
    public function exportOnlineMonthly(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->subMonths(12)->startOfMonth();
        $endDate = now()->endOfMonth();

        $monthlyData = $this->getOnlineMonthlyData($store->id, $startDate, $endDate);

        return $this->exportToCsv($monthlyData, 'buys-online-monthly-'.now()->format('Y-m-d').'.csv');
    }

    /**
     * Export Trade-In MTD to CSV.
     */
    public function exportTradeIn(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->startOfMonth();
        $endDate = now();

        $dailyData = $this->getTradeInDailyData($store->id, $startDate, $endDate);

        return $this->exportToCsv($dailyData, 'buys-trade-in-mtd-'.now()->format('Y-m-d').'.csv');
    }

    /**
     * Export Trade-In Monthly to CSV.
     */
    public function exportTradeInMonthly(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->subMonths(12)->startOfMonth();
        $endDate = now()->endOfMonth();

        $monthlyData = $this->getTradeInMonthlyData($store->id, $startDate, $endDate);

        return $this->exportToCsv($monthlyData, 'buys-trade-in-monthly-'.now()->format('Y-m-d').'.csv');
    }

    /**
     * In-Store Buys Report - Year over Year.
     */
    public function inStoreYearly(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $yearlyData = $this->getInStoreYearlyData($store->id);
        $totals = $this->calculateTotals($yearlyData);

        return Inertia::render('reports/buys/InStoreYearly', [
            'yearlyData' => $yearlyData,
            'totals' => $totals,
        ]);
    }

    /**
     * Online Buys Report - Year over Year.
     */
    public function onlineYearly(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $yearlyData = $this->getOnlineYearlyData($store->id);
        $totals = $this->calculateTotals($yearlyData);

        return Inertia::render('reports/buys/OnlineYearly', [
            'yearlyData' => $yearlyData,
            'totals' => $totals,
        ]);
    }

    /**
     * Trade-In Buys Report - Year over Year.
     */
    public function tradeInYearly(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $yearlyData = $this->getTradeInYearlyData($store->id);
        $totals = $this->calculateTotals($yearlyData);

        return Inertia::render('reports/buys/TradeInYearly', [
            'yearlyData' => $yearlyData,
            'totals' => $totals,
        ]);
    }

    /**
     * Export In-Store Yearly to CSV.
     */
    public function exportInStoreYearly(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $yearlyData = $this->getInStoreYearlyData($store->id);

        return $this->exportToCsv($yearlyData, 'buys-in-store-yearly-'.now()->format('Y-m-d').'.csv');
    }

    /**
     * Export Online Yearly to CSV.
     */
    public function exportOnlineYearly(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $yearlyData = $this->getOnlineYearlyData($store->id);

        return $this->exportToCsv($yearlyData, 'buys-online-yearly-'.now()->format('Y-m-d').'.csv');
    }

    /**
     * Export Trade-In Yearly to CSV.
     */
    public function exportTradeInYearly(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $yearlyData = $this->getTradeInYearlyData($store->id);

        return $this->exportToCsv($yearlyData, 'buys-trade-in-yearly-'.now()->format('Y-m-d').'.csv');
    }

    /**
     * Get in-store daily aggregated data.
     * In-store means in_house type with source NOT online.
     */
    protected function getInStoreDailyData(int $storeId, Carbon $startDate, Carbon $endDate)
    {
        return $this->getDailyBuysData($storeId, $startDate, $endDate, function ($query) {
            $query->where('type', Transaction::TYPE_IN_STORE)
                ->where(function ($q) {
                    $q->whereNull('source')
                        ->orWhere('source', '!=', Transaction::SOURCE_ONLINE);
                })
                ->where(function ($q) {
                    $q->whereNull('source')
                        ->orWhere('source', '!=', Transaction::SOURCE_TRADE_IN);
                });
        });
    }

    /**
     * Get in-store monthly aggregated data.
     */
    protected function getInStoreMonthlyData(int $storeId, Carbon $startDate, Carbon $endDate)
    {
        return $this->getMonthlyBuysData($storeId, $startDate, $endDate, function ($query) {
            $query->where('type', Transaction::TYPE_IN_STORE)
                ->where(function ($q) {
                    $q->whereNull('source')
                        ->orWhere('source', '!=', Transaction::SOURCE_ONLINE);
                })
                ->where(function ($q) {
                    $q->whereNull('source')
                        ->orWhere('source', '!=', Transaction::SOURCE_TRADE_IN);
                });
        });
    }

    /**
     * Get online daily aggregated data.
     * Online means mail_in type OR source = online.
     */
    protected function getOnlineDailyData(int $storeId, Carbon $startDate, Carbon $endDate)
    {
        return $this->getDailyBuysData($storeId, $startDate, $endDate, function ($query) {
            $query->where(function ($q) {
                $q->where('type', Transaction::TYPE_MAIL_IN)
                    ->orWhere('source', Transaction::SOURCE_ONLINE);
            });
        });
    }

    /**
     * Get online monthly aggregated data.
     */
    protected function getOnlineMonthlyData(int $storeId, Carbon $startDate, Carbon $endDate)
    {
        return $this->getMonthlyBuysData($storeId, $startDate, $endDate, function ($query) {
            $query->where(function ($q) {
                $q->where('type', Transaction::TYPE_MAIL_IN)
                    ->orWhere('source', Transaction::SOURCE_ONLINE);
            });
        });
    }

    /**
     * Get trade-in daily aggregated data.
     * Trade-in means source = trade_in (trade-in transactions).
     */
    protected function getTradeInDailyData(int $storeId, Carbon $startDate, Carbon $endDate)
    {
        return $this->getDailyBuysData($storeId, $startDate, $endDate, function ($query) {
            $query->where('source', Transaction::SOURCE_TRADE_IN);
        });
    }

    /**
     * Get trade-in monthly aggregated data.
     */
    protected function getTradeInMonthlyData(int $storeId, Carbon $startDate, Carbon $endDate)
    {
        return $this->getMonthlyBuysData($storeId, $startDate, $endDate, function ($query) {
            $query->where('source', Transaction::SOURCE_TRADE_IN);
        });
    }

    /**
     * Get in-store yearly aggregated data.
     */
    protected function getInStoreYearlyData(int $storeId)
    {
        return $this->getYearlyBuysData($storeId, function ($query) {
            $query->where('type', Transaction::TYPE_IN_STORE)
                ->where(function ($q) {
                    $q->whereNull('source')
                        ->orWhere('source', '!=', Transaction::SOURCE_ONLINE);
                })
                ->where(function ($q) {
                    $q->whereNull('source')
                        ->orWhere('source', '!=', Transaction::SOURCE_TRADE_IN);
                });
        });
    }

    /**
     * Get online yearly aggregated data.
     */
    protected function getOnlineYearlyData(int $storeId)
    {
        return $this->getYearlyBuysData($storeId, function ($query) {
            $query->where(function ($q) {
                $q->where('type', Transaction::TYPE_MAIL_IN)
                    ->orWhere('source', Transaction::SOURCE_ONLINE);
            });
        });
    }

    /**
     * Get trade-in yearly aggregated data.
     */
    protected function getTradeInYearlyData(int $storeId)
    {
        return $this->getYearlyBuysData($storeId, function ($query) {
            $query->where('source', Transaction::SOURCE_TRADE_IN);
        });
    }

    /**
     * Get daily buys data with custom filter.
     * Includes all transactions that have reached payment_processed status.
     *
     * @param  array<int>|null  $categoryIds
     */
    protected function getDailyBuysData(int $storeId, Carbon $startDate, Carbon $endDate, callable $filter, ?array $categoryIds = null)
    {
        $paymentProcessedStatusId = $this->getPaymentProcessedStatusId($storeId);

        $query = Transaction::query()
            ->where('store_id', $storeId)
            ->where('status_id', $paymentProcessedStatusId)
            ->whereNotNull('payment_processed_at')
            ->whereBetween('payment_processed_at', [$startDate, $endDate])
            ->with(['items']);

        $filter($query);

        // Apply category filter if set
        if ($categoryIds) {
            $query->whereHas('items', fn ($q) => $q->whereIn('category_id', $categoryIds));
        }

        $transactions = $query->get();

        // Group by day using payment_processed_at
        $grouped = $transactions->groupBy(fn ($t) => Carbon::parse($t->payment_processed_at)->format('Y-m-d'));

        // Generate all days in range
        $days = collect();
        $current = $startDate->copy();
        while ($current <= $endDate) {
            $key = $current->format('Y-m-d');
            $dayTransactions = $grouped->get($key, collect());

            $buysCount = $dayTransactions->count();
            $purchaseAmt = $dayTransactions->sum('final_offer');
            // Estimated value comes from sum of item prices (resale value)
            $estimatedValue = $dayTransactions->sum(fn ($t) => $t->items->sum('price'));
            $profit = $estimatedValue - $purchaseAmt;
            $profitPercent = $purchaseAmt > 0 ? ($profit / $purchaseAmt) * 100 : 0;
            $avgBuyPrice = $buysCount > 0 ? $purchaseAmt / $buysCount : 0;

            $days->push([
                'date' => $current->format('M d, Y'),
                'date_key' => $current->format('Y-m-d'),
                'buys_count' => $buysCount,
                'purchase_amt' => $purchaseAmt,
                'estimated_value' => $estimatedValue,
                'profit' => $profit,
                'profit_percent' => $profitPercent,
                'avg_buy_price' => $avgBuyPrice,
            ]);

            $current->addDay();
        }

        return $days->reverse()->values();
    }

    /**
     * Get yearly buys data with custom filter.
     * Includes all transactions that have reached payment_processed status.
     *
     * @param  array<int>|null  $categoryIds
     */
    protected function getYearlyBuysData(int $storeId, callable $filter, ?array $categoryIds = null)
    {
        $paymentProcessedStatusId = $this->getPaymentProcessedStatusId($storeId);

        // Get last 5 years of data
        $startDate = now()->subYears(4)->startOfYear();
        $endDate = now()->endOfYear();

        $query = Transaction::query()
            ->where('store_id', $storeId)
            ->where('status_id', $paymentProcessedStatusId)
            ->whereNotNull('payment_processed_at')
            ->whereBetween('payment_processed_at', [$startDate, $endDate])
            ->with(['items']);

        $filter($query);

        // Apply category filter if set
        if ($categoryIds) {
            $query->whereHas('items', fn ($q) => $q->whereIn('category_id', $categoryIds));
        }

        $transactions = $query->get();

        // Group by year using payment_processed_at
        $grouped = $transactions->groupBy(fn ($t) => Carbon::parse($t->payment_processed_at)->format('Y'));

        // Generate all years in range
        $years = collect();
        $current = $startDate->copy();
        while ($current <= $endDate) {
            $key = $current->format('Y');
            $yearTransactions = $grouped->get($key, collect());

            $buysCount = $yearTransactions->count();
            $purchaseAmt = $yearTransactions->sum('final_offer');
            // Estimated value comes from sum of item prices (resale value)
            $estimatedValue = $yearTransactions->sum(fn ($t) => $t->items->sum('price'));
            $profit = $estimatedValue - $purchaseAmt;
            $profitPercent = $purchaseAmt > 0 ? ($profit / $purchaseAmt) * 100 : 0;
            $avgBuyPrice = $buysCount > 0 ? $purchaseAmt / $buysCount : 0;

            $years->push([
                'date' => $current->format('Y'),
                'start_date' => $current->copy()->startOfYear()->format('Y-m-d'),
                'end_date' => $current->copy()->endOfYear()->format('Y-m-d'),
                'buys_count' => $buysCount,
                'purchase_amt' => $purchaseAmt,
                'estimated_value' => $estimatedValue,
                'profit' => $profit,
                'profit_percent' => $profitPercent,
                'avg_buy_price' => $avgBuyPrice,
            ]);

            $current->addYear();
        }

        return $years->reverse()->values();
    }

    /**
     * Get monthly buys data with custom filter.
     * Includes all transactions that have reached payment_processed status.
     *
     * @param  array<int>|null  $categoryIds
     */
    protected function getMonthlyBuysData(int $storeId, Carbon $startDate, Carbon $endDate, callable $filter, ?array $categoryIds = null)
    {
        $paymentProcessedStatusId = $this->getPaymentProcessedStatusId($storeId);

        $query = Transaction::query()
            ->where('store_id', $storeId)
            ->where('status_id', $paymentProcessedStatusId)
            ->whereNotNull('payment_processed_at')
            ->whereBetween('payment_processed_at', [$startDate, $endDate])
            ->with(['items']);

        $filter($query);

        // Apply category filter if set
        if ($categoryIds) {
            $query->whereHas('items', fn ($q) => $q->whereIn('category_id', $categoryIds));
        }

        $transactions = $query->get();

        // Group by month using payment_processed_at
        $grouped = $transactions->groupBy(fn ($t) => Carbon::parse($t->payment_processed_at)->format('Y-m'));

        // Generate all months in range
        $months = collect();
        $current = $startDate->copy();
        while ($current <= $endDate) {
            $key = $current->format('Y-m');
            $monthTransactions = $grouped->get($key, collect());

            $buysCount = $monthTransactions->count();
            $purchaseAmt = $monthTransactions->sum('final_offer');
            // Estimated value comes from sum of item prices (resale value)
            $estimatedValue = $monthTransactions->sum(fn ($t) => $t->items->sum('price'));
            $profit = $estimatedValue - $purchaseAmt;
            $profitPercent = $purchaseAmt > 0 ? ($profit / $purchaseAmt) * 100 : 0;
            $avgBuyPrice = $buysCount > 0 ? $purchaseAmt / $buysCount : 0;

            $months->push([
                'date' => $current->format('M Y'),
                'start_date' => $current->copy()->startOfMonth()->format('Y-m-d'),
                'end_date' => $current->copy()->endOfMonth()->format('Y-m-d'),
                'buys_count' => $buysCount,
                'purchase_amt' => $purchaseAmt,
                'estimated_value' => $estimatedValue,
                'profit' => $profit,
                'profit_percent' => $profitPercent,
                'avg_buy_price' => $avgBuyPrice,
            ]);

            $current->addMonth();
        }

        return $months->reverse()->values();
    }

    /**
     * Calculate totals from aggregated data.
     */
    protected function calculateTotals($data): array
    {
        $buysCount = $data->sum('buys_count');
        $purchaseAmt = $data->sum('purchase_amt');
        $estimatedValue = $data->sum('estimated_value');
        $profit = $estimatedValue - $purchaseAmt;
        $profitPercent = $purchaseAmt > 0 ? ($profit / $purchaseAmt) * 100 : 0;
        $avgBuyPrice = $buysCount > 0 ? $purchaseAmt / $buysCount : 0;

        return [
            'buys_count' => $buysCount,
            'purchase_amt' => $purchaseAmt,
            'estimated_value' => $estimatedValue,
            'profit' => $profit,
            'profit_percent' => $profitPercent,
            'avg_buy_price' => $avgBuyPrice,
        ];
    }

    /**
     * Export data to CSV.
     */
    protected function exportToCsv($data, string $filename): StreamedResponse
    {
        $totals = $this->calculateTotals($data);

        return response()->streamDownload(function () use ($data, $totals) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Date',
                '# of Buys',
                'Purchase Amt',
                'Estimated Value',
                'Profit',
                'Profit %',
                'Avg Buy Price',
            ]);

            foreach ($data as $row) {
                fputcsv($handle, [
                    $row['date'],
                    $row['buys_count'],
                    number_format($row['purchase_amt'], 2),
                    number_format($row['estimated_value'], 2),
                    number_format($row['profit'], 2),
                    number_format($row['profit_percent'], 2).'%',
                    number_format($row['avg_buy_price'], 2),
                ]);
            }

            // Totals row
            fputcsv($handle, [
                'TOTALS',
                $totals['buys_count'],
                number_format($totals['purchase_amt'], 2),
                number_format($totals['estimated_value'], 2),
                number_format($totals['profit'], 2),
                number_format($totals['profit_percent'], 2).'%',
                number_format($totals['avg_buy_price'], 2),
            ]);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
