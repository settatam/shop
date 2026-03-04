<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\StoreContext;
use App\Traits\SendsReportEmails;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryReportController extends Controller
{
    use SendsReportEmails;

    public function __construct(
        protected StoreContext $storeContext,
    ) {}

    /**
     * Inventory report - grouped by category with drill-down support.
     */
    public function index(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $weekStart = now()->subWeek()->startOfDay();
        $categoryId = $request->query('category_id');
        $viewAll = $request->boolean('view_all', false);

        // Get current category and breadcrumb if drilling down
        $currentCategory = null;
        $breadcrumb = [];

        if ($categoryId) {
            $currentCategory = Category::where('store_id', $store->id)->find($categoryId);
            if ($currentCategory) {
                $breadcrumb = $this->getCategoryBreadcrumb($currentCategory);
            }
        }

        // Get inventory data - either hierarchical or flat
        if ($viewAll) {
            // Flat view - all categories
            $categoryData = $this->getCategoryInventoryData($store->id, $weekStart);
        } else {
            // Hierarchical view - show children of current category (or root categories)
            $categoryData = $this->getHierarchicalCategoryData($store->id, $weekStart, $categoryId);
        }

        // Get weekly trend data (last 8 weeks)
        $weeklyTrend = $this->getWeeklyTrendData($store->id);

        // Calculate totals (always show overall totals)
        $overallTotals = $this->getOverallTotals($store->id, $weekStart);

        // Root category IDs for determining hierarchy level in frontend
        $rootCategoryIds = Category::where('store_id', $store->id)
            ->roots()
            ->pluck('id')
            ->toArray();

        return Inertia::render('reports/inventory/Index', [
            'categoryData' => $categoryData,
            'totals' => $overallTotals,
            'weeklyTrend' => $weeklyTrend,
            'currentCategory' => $currentCategory ? [
                'id' => $currentCategory->id,
                'name' => $currentCategory->name,
            ] : null,
            'breadcrumb' => $breadcrumb,
            'viewAll' => $viewAll,
            'rootCategoryIds' => $rootCategoryIds,
        ]);
    }

    /**
     * Get the breadcrumb path for a category.
     *
     * @return array<int, array{id: int, name: string}>
     */
    protected function getCategoryBreadcrumb(Category $category): array
    {
        $breadcrumb = [];
        $current = $category;

        while ($current->parent) {
            $current = $current->parent;
            array_unshift($breadcrumb, [
                'id' => $current->id,
                'name' => $current->name,
            ]);
        }

        return $breadcrumb;
    }

    /**
     * Get overall inventory totals for the store.
     *
     * @return array{total_stock: int, total_value: float, added_this_week: int, cost_added: float, deleted_this_week: int, deleted_cost: float, projected_profit: float}
     */
    protected function getOverallTotals(int $storeId, Carbon $weekStart): array
    {
        // Total inventory (exclude soft-deleted products)
        $inventory = DB::table('inventory')
            ->where('inventory.store_id', $storeId)
            ->join('product_variants', 'inventory.product_variant_id', '=', 'product_variants.id')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->whereNull('products.deleted_at')
            ->selectRaw('
                COALESCE(SUM(inventory.quantity), 0) as total_stock,
                COALESCE(SUM(inventory.quantity * inventory.unit_cost), 0) as total_value,
                COALESCE(SUM(inventory.quantity * product_variants.price), 0) as total_retail_value,
                COALESCE(SUM(inventory.quantity * COALESCE(product_variants.wholesale_price, product_variants.cost, inventory.unit_cost, 0)), 0) as total_cost_basis
            ')
            ->first();

        // Weekly additions (exclude soft-deleted products)
        $additions = DB::table('inventory_adjustments')
            ->where('inventory_adjustments.store_id', $storeId)
            ->where('inventory_adjustments.created_at', '>=', $weekStart)
            ->where('inventory_adjustments.quantity_change', '>', 0)
            ->join('inventory', 'inventory_adjustments.inventory_id', '=', 'inventory.id')
            ->join('product_variants', 'inventory.product_variant_id', '=', 'product_variants.id')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->whereNull('products.deleted_at')
            ->selectRaw('COALESCE(SUM(inventory_adjustments.quantity_change), 0) as added, COALESCE(SUM(inventory_adjustments.total_cost_impact), 0) as cost_added')
            ->first();

        // Weekly deletions (exclude soft-deleted products)
        $deletions = DB::table('inventory_adjustments')
            ->where('inventory_adjustments.store_id', $storeId)
            ->where('inventory_adjustments.created_at', '>=', $weekStart)
            ->where('inventory_adjustments.quantity_change', '<', 0)
            ->join('inventory', 'inventory_adjustments.inventory_id', '=', 'inventory.id')
            ->join('product_variants', 'inventory.product_variant_id', '=', 'product_variants.id')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->whereNull('products.deleted_at')
            ->selectRaw('COALESCE(SUM(ABS(inventory_adjustments.quantity_change)), 0) as deleted, COALESCE(SUM(ABS(inventory_adjustments.total_cost_impact)), 0) as cost_deleted')
            ->first();

        $totalValue = (float) ($inventory->total_value ?? 0);
        $totalRetailValue = (float) ($inventory->total_retail_value ?? 0);
        $totalCostBasis = (float) ($inventory->total_cost_basis ?? 0);

        return [
            'total_stock' => (int) ($inventory->total_stock ?? 0),
            'total_value' => $totalValue,
            'added_this_week' => (int) ($additions->added ?? 0),
            'cost_added' => (float) ($additions->cost_added ?? 0),
            'deleted_this_week' => (int) ($deletions->deleted ?? 0),
            'deleted_cost' => (float) ($deletions->cost_deleted ?? 0),
            'projected_profit' => $totalRetailValue - $totalCostBasis,
        ];
    }

    /**
     * Get hierarchical inventory data for a specific level.
     */
    protected function getHierarchicalCategoryData(int $storeId, Carbon $weekStart, ?int $parentCategoryId)
    {
        // Get categories at this level
        $query = Category::where('store_id', $storeId);

        if ($parentCategoryId === null) {
            $query->roots();
        } else {
            $query->where('parent_id', $parentCategoryId);
        }

        $categories = $query->orderBy('name')->get();

        // Build result with aggregated descendant data
        $result = collect();

        foreach ($categories as $category) {
            // Get all descendant category IDs including this one
            $categoryIds = $this->getAllDescendantIds($category);

            // Get aggregated inventory data for all these categories
            $inventoryData = $this->getAggregatedInventoryForCategories($storeId, $categoryIds, $weekStart);

            // Check if there's any inventory or activity
            if ($inventoryData['total_stock'] > 0 ||
                $inventoryData['added_this_week'] > 0 ||
                $inventoryData['deleted_this_week'] > 0) {

                $hasChildren = $category->children()->exists();

                $result->push([
                    'category_id' => $category->id,
                    'parent_id' => $category->parent_id,
                    'category' => $category->name,
                    'total_stock' => $inventoryData['total_stock'],
                    'total_value' => $inventoryData['total_value'],
                    'added_this_week' => $inventoryData['added_this_week'],
                    'cost_added' => $inventoryData['cost_added'],
                    'deleted_this_week' => $inventoryData['deleted_this_week'],
                    'deleted_cost' => $inventoryData['deleted_cost'],
                    'projected_profit' => $inventoryData['projected_profit'],
                    'has_children' => $hasChildren,
                ]);
            }
        }

        // Add uncategorized items if at root level
        if ($parentCategoryId === null) {
            $uncategorized = $this->getUncategorizedInventory($storeId, $weekStart);
            if ($uncategorized['total_stock'] > 0 ||
                $uncategorized['added_this_week'] > 0 ||
                $uncategorized['deleted_this_week'] > 0) {
                $result->push([
                    'category_id' => null,
                    'parent_id' => null,
                    'category' => 'Uncategorized',
                    'total_stock' => $uncategorized['total_stock'],
                    'total_value' => $uncategorized['total_value'],
                    'added_this_week' => $uncategorized['added_this_week'],
                    'cost_added' => $uncategorized['cost_added'],
                    'deleted_this_week' => $uncategorized['deleted_this_week'],
                    'deleted_cost' => $uncategorized['deleted_cost'],
                    'projected_profit' => $uncategorized['projected_profit'],
                    'has_children' => false,
                ]);
            }
        }

        return $result->sortBy('category')->values();
    }

    /**
     * Get all descendant category IDs for a category.
     *
     * @return array<int>
     */
    protected function getAllDescendantIds(Category $category): array
    {
        $ids = [$category->id];

        foreach ($category->children as $child) {
            $ids = array_merge($ids, $this->getAllDescendantIds($child));
        }

        return $ids;
    }

    /**
     * Get aggregated inventory data for a set of category IDs.
     *
     * @param  array<int>  $categoryIds
     * @return array{total_stock: int, total_value: float, added_this_week: int, cost_added: float, deleted_this_week: int, deleted_cost: float, projected_profit: float}
     */
    protected function getAggregatedInventoryForCategories(int $storeId, array $categoryIds, Carbon $weekStart): array
    {
        // Get inventory totals
        $inventory = DB::table('inventory')
            ->where('inventory.store_id', $storeId)
            ->join('product_variants', 'inventory.product_variant_id', '=', 'product_variants.id')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->whereNull('products.deleted_at')
            ->whereIn('products.category_id', $categoryIds)
            ->selectRaw('
                COALESCE(SUM(inventory.quantity), 0) as total_stock,
                COALESCE(SUM(inventory.quantity * inventory.unit_cost), 0) as total_value,
                COALESCE(SUM(inventory.quantity * product_variants.price), 0) as total_retail_value,
                COALESCE(SUM(inventory.quantity * COALESCE(product_variants.wholesale_price, product_variants.cost, inventory.unit_cost, 0)), 0) as total_cost_basis
            ')
            ->first();

        // Get weekly additions
        $additions = DB::table('inventory_adjustments')
            ->where('inventory_adjustments.store_id', $storeId)
            ->where('inventory_adjustments.created_at', '>=', $weekStart)
            ->where('inventory_adjustments.quantity_change', '>', 0)
            ->join('inventory', 'inventory_adjustments.inventory_id', '=', 'inventory.id')
            ->join('product_variants', 'inventory.product_variant_id', '=', 'product_variants.id')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->whereNull('products.deleted_at')
            ->whereIn('products.category_id', $categoryIds)
            ->selectRaw('COALESCE(SUM(inventory_adjustments.quantity_change), 0) as added, COALESCE(SUM(inventory_adjustments.total_cost_impact), 0) as cost_added')
            ->first();

        // Get weekly deletions
        $deletions = DB::table('inventory_adjustments')
            ->where('inventory_adjustments.store_id', $storeId)
            ->where('inventory_adjustments.created_at', '>=', $weekStart)
            ->where('inventory_adjustments.quantity_change', '<', 0)
            ->join('inventory', 'inventory_adjustments.inventory_id', '=', 'inventory.id')
            ->join('product_variants', 'inventory.product_variant_id', '=', 'product_variants.id')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->whereNull('products.deleted_at')
            ->whereIn('products.category_id', $categoryIds)
            ->selectRaw('COALESCE(SUM(ABS(inventory_adjustments.quantity_change)), 0) as deleted, COALESCE(SUM(ABS(inventory_adjustments.total_cost_impact)), 0) as cost_deleted')
            ->first();

        $totalValue = (float) ($inventory->total_value ?? 0);
        $totalRetailValue = (float) ($inventory->total_retail_value ?? 0);
        $totalCostBasis = (float) ($inventory->total_cost_basis ?? 0);

        return [
            'total_stock' => (int) ($inventory->total_stock ?? 0),
            'total_value' => $totalValue,
            'added_this_week' => (int) ($additions->added ?? 0),
            'cost_added' => (float) ($additions->cost_added ?? 0),
            'deleted_this_week' => (int) ($deletions->deleted ?? 0),
            'deleted_cost' => (float) ($deletions->cost_deleted ?? 0),
            'projected_profit' => $totalRetailValue - $totalCostBasis,
        ];
    }

    /**
     * Get inventory data for uncategorized products.
     *
     * @return array{total_stock: int, total_value: float, added_this_week: int, cost_added: float, deleted_this_week: int, deleted_cost: float, projected_profit: float}
     */
    protected function getUncategorizedInventory(int $storeId, Carbon $weekStart): array
    {
        $inventory = DB::table('inventory')
            ->where('inventory.store_id', $storeId)
            ->join('product_variants', 'inventory.product_variant_id', '=', 'product_variants.id')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->whereNull('products.deleted_at')
            ->whereNull('products.category_id')
            ->selectRaw('
                COALESCE(SUM(inventory.quantity), 0) as total_stock,
                COALESCE(SUM(inventory.quantity * inventory.unit_cost), 0) as total_value,
                COALESCE(SUM(inventory.quantity * product_variants.price), 0) as total_retail_value,
                COALESCE(SUM(inventory.quantity * COALESCE(product_variants.wholesale_price, product_variants.cost, inventory.unit_cost, 0)), 0) as total_cost_basis
            ')
            ->first();

        $additions = DB::table('inventory_adjustments')
            ->where('inventory_adjustments.store_id', $storeId)
            ->where('inventory_adjustments.created_at', '>=', $weekStart)
            ->where('inventory_adjustments.quantity_change', '>', 0)
            ->join('inventory', 'inventory_adjustments.inventory_id', '=', 'inventory.id')
            ->join('product_variants', 'inventory.product_variant_id', '=', 'product_variants.id')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->whereNull('products.deleted_at')
            ->whereNull('products.category_id')
            ->selectRaw('COALESCE(SUM(inventory_adjustments.quantity_change), 0) as added, COALESCE(SUM(inventory_adjustments.total_cost_impact), 0) as cost_added')
            ->first();

        $deletions = DB::table('inventory_adjustments')
            ->where('inventory_adjustments.store_id', $storeId)
            ->where('inventory_adjustments.created_at', '>=', $weekStart)
            ->where('inventory_adjustments.quantity_change', '<', 0)
            ->join('inventory', 'inventory_adjustments.inventory_id', '=', 'inventory.id')
            ->join('product_variants', 'inventory.product_variant_id', '=', 'product_variants.id')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->whereNull('products.deleted_at')
            ->whereNull('products.category_id')
            ->selectRaw('COALESCE(SUM(ABS(inventory_adjustments.quantity_change)), 0) as deleted, COALESCE(SUM(ABS(inventory_adjustments.total_cost_impact)), 0) as cost_deleted')
            ->first();

        $totalValue = (float) ($inventory->total_value ?? 0);
        $totalRetailValue = (float) ($inventory->total_retail_value ?? 0);
        $totalCostBasis = (float) ($inventory->total_cost_basis ?? 0);

        return [
            'total_stock' => (int) ($inventory->total_stock ?? 0),
            'total_value' => $totalValue,
            'added_this_week' => (int) ($additions->added ?? 0),
            'cost_added' => (float) ($additions->cost_added ?? 0),
            'deleted_this_week' => (int) ($deletions->deleted ?? 0),
            'deleted_cost' => (float) ($deletions->cost_deleted ?? 0),
            'projected_profit' => $totalRetailValue - $totalCostBasis,
        ];
    }

    /**
     * Week over week inventory report.
     * Supports filtering by start/end month+year range.
     */
    public function weekly(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();

        $startMonth = (int) $request->query('start_month', now()->subMonths(3)->month);
        $startYear = (int) $request->query('start_year', now()->subMonths(3)->year);
        $endMonth = (int) $request->query('end_month', now()->month);
        $endYear = (int) $request->query('end_year', now()->year);

        $rangeStart = Carbon::createFromDate($startYear, $startMonth, 1)->startOfMonth();
        $rangeEnd = Carbon::createFromDate($endYear, $endMonth, 1)->endOfMonth();

        $weeklyData = $this->getWeekOverWeekData($store->id, $rangeStart, $rangeEnd);

        $totals = [
            'items_added' => $weeklyData->sum('items_added'),
            'cost_added' => $weeklyData->sum('cost_added'),
            'items_removed' => $weeklyData->sum('items_removed'),
            'cost_removed' => $weeklyData->sum('cost_removed'),
            'net_items' => $weeklyData->sum('net_items'),
            'net_cost' => $weeklyData->sum('net_cost'),
        ];

        $dateRangeLabel = $rangeStart->format('M Y').' - '.$rangeEnd->format('M Y');

        return Inertia::render('reports/inventory/Weekly', [
            'weeklyData' => $weeklyData,
            'totals' => $totals,
            'startMonth' => $startMonth,
            'startYear' => $startYear,
            'endMonth' => $endMonth,
            'endYear' => $endYear,
            'dateRangeLabel' => $dateRangeLabel,
        ]);
    }

    /**
     * Month over month inventory report.
     * Supports filtering by start/end month+year range.
     */
    public function monthly(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();

        $startMonth = (int) $request->query('start_month', now()->subMonths(12)->month);
        $startYear = (int) $request->query('start_year', now()->subMonths(12)->year);
        $endMonth = (int) $request->query('end_month', now()->month);
        $endYear = (int) $request->query('end_year', now()->year);

        $rangeStart = Carbon::createFromDate($startYear, $startMonth, 1)->startOfMonth();
        $rangeEnd = Carbon::createFromDate($endYear, $endMonth, 1)->endOfMonth();

        $monthlyData = $this->getMonthOverMonthData($store->id, $rangeStart, $rangeEnd);

        $totals = [
            'items_added' => $monthlyData->sum('items_added'),
            'cost_added' => $monthlyData->sum('cost_added'),
            'items_removed' => $monthlyData->sum('items_removed'),
            'cost_removed' => $monthlyData->sum('cost_removed'),
            'net_items' => $monthlyData->sum('net_items'),
            'net_cost' => $monthlyData->sum('net_cost'),
        ];

        $dateRangeLabel = $rangeStart->format('M Y').' - '.$rangeEnd->format('M Y');

        return Inertia::render('reports/inventory/Monthly', [
            'monthlyData' => $monthlyData,
            'totals' => $totals,
            'startMonth' => $startMonth,
            'startYear' => $startYear,
            'endMonth' => $endMonth,
            'endYear' => $endYear,
            'dateRangeLabel' => $dateRangeLabel,
        ]);
    }

    /**
     * Year over year inventory report.
     * Supports filtering by start/end year range.
     */
    public function yearly(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();

        $startYear = (int) $request->query('start_year', now()->year - 4);
        $endYear = (int) $request->query('end_year', now()->year);

        $yearlyData = $this->getYearOverYearData($store->id, $startYear, $endYear);

        $totals = [
            'items_added' => $yearlyData->sum('items_added'),
            'cost_added' => $yearlyData->sum('cost_added'),
            'items_removed' => $yearlyData->sum('items_removed'),
            'cost_removed' => $yearlyData->sum('cost_removed'),
            'net_items' => $yearlyData->sum('net_items'),
            'net_cost' => $yearlyData->sum('net_cost'),
        ];

        return Inertia::render('reports/inventory/Yearly', [
            'yearlyData' => $yearlyData,
            'totals' => $totals,
            'startYear' => $startYear,
            'endYear' => $endYear,
        ]);
    }

    /**
     * Daily inventory report.
     * Supports filtering by start/end date range.
     */
    public function daily(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();

        $startDate = $request->query('start_date', now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->query('end_date', now()->format('Y-m-d'));

        $rangeStart = Carbon::parse($startDate)->startOfDay();
        $rangeEnd = Carbon::parse($endDate)->endOfDay();

        $dailyData = $this->getDailyInventoryData($store->id, $rangeStart, $rangeEnd);

        $totals = [
            'items_added' => $dailyData->sum('items_added'),
            'cost_added' => $dailyData->sum('cost_added'),
            'items_removed' => $dailyData->sum('items_removed'),
            'cost_removed' => $dailyData->sum('cost_removed'),
            'net_items' => $dailyData->sum('net_items'),
            'net_cost' => $dailyData->sum('net_cost'),
        ];

        $dateRangeLabel = $rangeStart->format('M d, Y').' - '.$rangeEnd->format('M d, Y');

        return Inertia::render('reports/inventory/Daily', [
            'dailyData' => $dailyData,
            'totals' => $totals,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dateRangeLabel' => $dateRangeLabel,
        ]);
    }

    /**
     * Export inventory report to CSV.
     */
    public function export(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $weekStart = now()->subWeek()->startOfDay();

        $categoryData = $this->getCategoryInventoryData($store->id, $weekStart);
        $totals = [
            'total_stock' => $categoryData->sum('total_stock'),
            'total_value' => $categoryData->sum('total_value'),
            'added_this_week' => $categoryData->sum('added_this_week'),
            'cost_added' => $categoryData->sum('cost_added'),
            'deleted_this_week' => $categoryData->sum('deleted_this_week'),
            'deleted_cost' => $categoryData->sum('deleted_cost'),
            'projected_profit' => $categoryData->sum('projected_profit'),
        ];

        $filename = 'inventory-report-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($categoryData, $totals) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Category',
                'Total Stock',
                'Total Value ($)',
                'Added This Week',
                'Cost Added ($)',
                'Deleted This Week',
                'Deleted Cost ($)',
                'Projected Profit ($)',
            ]);

            foreach ($categoryData as $row) {
                fputcsv($handle, [
                    $row['category'],
                    $row['total_stock'],
                    number_format($row['total_value'], 2),
                    $row['added_this_week'],
                    number_format($row['cost_added'], 2),
                    $row['deleted_this_week'],
                    number_format($row['deleted_cost'], 2),
                    number_format($row['projected_profit'], 2),
                ]);
            }

            // Totals row
            fputcsv($handle, [
                'TOTALS',
                $totals['total_stock'],
                number_format($totals['total_value'], 2),
                $totals['added_this_week'],
                number_format($totals['cost_added'], 2),
                $totals['deleted_this_week'],
                number_format($totals['deleted_cost'], 2),
                number_format($totals['projected_profit'], 2),
            ]);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Export weekly report to CSV.
     */
    public function exportWeekly(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();

        $startMonth = (int) $request->query('start_month', now()->subMonths(3)->month);
        $startYear = (int) $request->query('start_year', now()->subMonths(3)->year);
        $endMonth = (int) $request->query('end_month', now()->month);
        $endYear = (int) $request->query('end_year', now()->year);

        $rangeStart = Carbon::createFromDate($startYear, $startMonth, 1)->startOfMonth();
        $rangeEnd = Carbon::createFromDate($endYear, $endMonth, 1)->endOfMonth();

        $weeklyData = $this->getWeekOverWeekData($store->id, $rangeStart, $rangeEnd);

        return $this->exportPeriodData($weeklyData, 'inventory-weekly-'.now()->format('Y-m-d').'.csv', 'Week');
    }

    /**
     * Export monthly report to CSV.
     */
    public function exportMonthly(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();

        $startMonth = (int) $request->query('start_month', now()->subMonths(12)->month);
        $startYear = (int) $request->query('start_year', now()->subMonths(12)->year);
        $endMonth = (int) $request->query('end_month', now()->month);
        $endYear = (int) $request->query('end_year', now()->year);

        $rangeStart = Carbon::createFromDate($startYear, $startMonth, 1)->startOfMonth();
        $rangeEnd = Carbon::createFromDate($endYear, $endMonth, 1)->endOfMonth();

        $monthlyData = $this->getMonthOverMonthData($store->id, $rangeStart, $rangeEnd);

        return $this->exportPeriodData($monthlyData, 'inventory-monthly-'.now()->format('Y-m-d').'.csv', 'Month');
    }

    /**
     * Export yearly report to CSV.
     */
    public function exportYearly(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();

        $startYear = (int) $request->query('start_year', now()->year - 4);
        $endYear = (int) $request->query('end_year', now()->year);

        $yearlyData = $this->getYearOverYearData($store->id, $startYear, $endYear);

        return $this->exportPeriodData($yearlyData, 'inventory-yearly-'.now()->format('Y-m-d').'.csv', 'Year');
    }

    /**
     * Export daily report to CSV.
     */
    public function exportDaily(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();

        $startDate = $request->query('start_date', now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->query('end_date', now()->format('Y-m-d'));

        $rangeStart = Carbon::parse($startDate)->startOfDay();
        $rangeEnd = Carbon::parse($endDate)->endOfDay();

        $dailyData = $this->getDailyInventoryData($store->id, $rangeStart, $rangeEnd);

        return $this->exportPeriodData($dailyData, 'inventory-daily-'.now()->format('Y-m-d').'.csv', 'Date');
    }

    /**
     * Export period data to CSV.
     */
    protected function exportPeriodData($data, string $filename, string $periodLabel): StreamedResponse
    {
        $totals = [
            'items_added' => $data->sum('items_added'),
            'cost_added' => $data->sum('cost_added'),
            'items_removed' => $data->sum('items_removed'),
            'cost_removed' => $data->sum('cost_removed'),
            'net_items' => $data->sum('net_items'),
            'net_cost' => $data->sum('net_cost'),
        ];

        return response()->streamDownload(function () use ($data, $totals, $periodLabel) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                $periodLabel,
                'Items Added',
                'Cost Added ($)',
                'Items Removed',
                'Cost Removed ($)',
                'Net Items',
                'Net Cost ($)',
            ]);

            foreach ($data as $row) {
                fputcsv($handle, [
                    $row['period'],
                    $row['items_added'],
                    number_format($row['cost_added'], 2),
                    $row['items_removed'],
                    number_format($row['cost_removed'], 2),
                    $row['net_items'],
                    number_format($row['net_cost'], 2),
                ]);
            }

            fputcsv($handle, [
                'TOTALS',
                $totals['items_added'],
                number_format($totals['cost_added'], 2),
                $totals['items_removed'],
                number_format($totals['cost_removed'], 2),
                $totals['net_items'],
                number_format($totals['net_cost'], 2),
            ]);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Email inventory by category report.
     */
    public function email(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $weekStart = now()->subWeek()->startOfDay();

        $categoryData = $this->getCategoryInventoryData($store->id, $weekStart);
        $totals = [
            'category' => '',
            'total_stock' => $categoryData->sum('total_stock'),
            'total_value' => $categoryData->sum('total_value'),
            'added_this_week' => $categoryData->sum('added_this_week'),
            'cost_added' => $categoryData->sum('cost_added'),
            'deleted_this_week' => $categoryData->sum('deleted_this_week'),
            'deleted_cost' => $categoryData->sum('deleted_cost'),
            'projected_profit' => $categoryData->sum('projected_profit'),
        ];

        $headers = ['Category', 'Total Stock', 'Total Value ($)', 'Added This Week', 'Cost Added ($)', 'Deleted This Week', 'Deleted Cost ($)', 'Projected Profit ($)'];

        $formatRow = fn ($row) => [
            $row['category'] ?? 'TOTALS',
            $row['total_stock'],
            '$'.number_format($row['total_value'], 2),
            $row['added_this_week'],
            '$'.number_format($row['cost_added'], 2),
            $row['deleted_this_week'],
            '$'.number_format($row['deleted_cost'], 2),
            '$'.number_format($row['projected_profit'], 2),
        ];

        return $this->sendReportEmail($request, 'Inventory by Category Report', 'Inventory by Category', $headers, $categoryData, $totals, $formatRow, 'inventory-report-'.now()->format('Y-m-d').'.csv', $store);
    }

    /**
     * Email weekly inventory report.
     */
    public function emailWeekly(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        $startMonth = (int) $request->query('start_month', now()->subMonths(3)->month);
        $startYear = (int) $request->query('start_year', now()->subMonths(3)->year);
        $endMonth = (int) $request->query('end_month', now()->month);
        $endYear = (int) $request->query('end_year', now()->year);

        $rangeStart = Carbon::createFromDate($startYear, $startMonth, 1)->startOfMonth();
        $rangeEnd = Carbon::createFromDate($endYear, $endMonth, 1)->endOfMonth();

        $weeklyData = $this->getWeekOverWeekData($store->id, $rangeStart, $rangeEnd);

        return $this->emailPeriodData($request, $weeklyData, 'Weekly Inventory Report', 'Week', $store);
    }

    /**
     * Email monthly inventory report.
     */
    public function emailMonthly(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        $startMonth = (int) $request->query('start_month', now()->subMonths(12)->month);
        $startYear = (int) $request->query('start_year', now()->subMonths(12)->year);
        $endMonth = (int) $request->query('end_month', now()->month);
        $endYear = (int) $request->query('end_year', now()->year);

        $rangeStart = Carbon::createFromDate($startYear, $startMonth, 1)->startOfMonth();
        $rangeEnd = Carbon::createFromDate($endYear, $endMonth, 1)->endOfMonth();

        $monthlyData = $this->getMonthOverMonthData($store->id, $rangeStart, $rangeEnd);

        return $this->emailPeriodData($request, $monthlyData, 'Monthly Inventory Report', 'Month', $store);
    }

    /**
     * Email yearly inventory report.
     */
    public function emailYearly(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        $startYear = (int) $request->query('start_year', now()->year - 4);
        $endYear = (int) $request->query('end_year', now()->year);

        $yearlyData = $this->getYearOverYearData($store->id, $startYear, $endYear);

        return $this->emailPeriodData($request, $yearlyData, 'Yearly Inventory Report', 'Year', $store);
    }

    /**
     * Email daily inventory report.
     */
    public function emailDaily(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        $startDate = $request->query('start_date', now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->query('end_date', now()->format('Y-m-d'));

        $rangeStart = Carbon::parse($startDate)->startOfDay();
        $rangeEnd = Carbon::parse($endDate)->endOfDay();

        $dailyData = $this->getDailyInventoryData($store->id, $rangeStart, $rangeEnd);

        return $this->emailPeriodData($request, $dailyData, 'Daily Inventory Report', 'Date', $store);
    }

    /**
     * Email period-based inventory data.
     */
    protected function emailPeriodData(Request $request, $data, string $title, string $periodLabel, $store): JsonResponse
    {
        $totals = [
            'period' => '',
            'items_added' => $data->sum('items_added'),
            'cost_added' => $data->sum('cost_added'),
            'items_removed' => $data->sum('items_removed'),
            'cost_removed' => $data->sum('cost_removed'),
            'net_items' => $data->sum('net_items'),
            'net_cost' => $data->sum('net_cost'),
        ];

        $headers = [$periodLabel, 'Items Added', 'Cost Added ($)', 'Items Removed', 'Cost Removed ($)', 'Net Items', 'Net Cost ($)'];

        $formatRow = fn ($row) => [
            $row['period'] ?? 'TOTALS',
            $row['items_added'],
            '$'.number_format($row['cost_added'], 2),
            $row['items_removed'],
            '$'.number_format($row['cost_removed'], 2),
            $row['net_items'],
            '$'.number_format($row['net_cost'], 2),
        ];

        return $this->sendReportEmail($request, $title, $title, $headers, $data, $totals, $formatRow, strtolower(str_replace(' ', '-', $title)).'-'.now()->format('Y-m-d').'.csv', $store);
    }

    /**
     * Get inventory data grouped by category.
     */
    protected function getCategoryInventoryData(int $storeId, Carbon $weekStart)
    {
        // Get all categories for the store
        $categories = Category::where('store_id', $storeId)
            ->orderBy('name')
            ->get();

        // Get inventory aggregated by category (using DB::table to avoid Eloquent casting issues)
        $inventoryByCategory = DB::table('inventory')
            ->where('inventory.store_id', $storeId)
            ->join('product_variants', 'inventory.product_variant_id', '=', 'product_variants.id')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->whereNull('products.deleted_at')
            ->select(
                'products.category_id',
                DB::raw('SUM(inventory.quantity) as total_stock'),
                DB::raw('SUM(inventory.quantity * inventory.unit_cost) as total_value'),
                DB::raw('SUM(inventory.quantity * product_variants.price) as total_retail_value'),
                DB::raw('SUM(inventory.quantity * COALESCE(product_variants.wholesale_price, product_variants.cost, inventory.unit_cost, 0)) as total_cost_basis')
            )
            ->groupBy('products.category_id')
            ->get()
            ->keyBy('category_id');

        // Get weekly adjustments aggregated by category (additions)
        $weeklyAdditions = DB::table('inventory_adjustments')
            ->where('inventory_adjustments.store_id', $storeId)
            ->where('inventory_adjustments.created_at', '>=', $weekStart)
            ->where('inventory_adjustments.quantity_change', '>', 0)
            ->join('inventory', 'inventory_adjustments.inventory_id', '=', 'inventory.id')
            ->join('product_variants', 'inventory.product_variant_id', '=', 'product_variants.id')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->whereNull('products.deleted_at')
            ->select(
                'products.category_id',
                DB::raw('SUM(inventory_adjustments.quantity_change) as added_count'),
                DB::raw('SUM(inventory_adjustments.total_cost_impact) as cost_added')
            )
            ->groupBy('products.category_id')
            ->get()
            ->keyBy('category_id');

        // Get weekly adjustments aggregated by category (deletions)
        $weeklyDeletions = DB::table('inventory_adjustments')
            ->where('inventory_adjustments.store_id', $storeId)
            ->where('inventory_adjustments.created_at', '>=', $weekStart)
            ->where('inventory_adjustments.quantity_change', '<', 0)
            ->join('inventory', 'inventory_adjustments.inventory_id', '=', 'inventory.id')
            ->join('product_variants', 'inventory.product_variant_id', '=', 'product_variants.id')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->whereNull('products.deleted_at')
            ->select(
                'products.category_id',
                DB::raw('SUM(ABS(inventory_adjustments.quantity_change)) as deleted_count'),
                DB::raw('SUM(ABS(inventory_adjustments.total_cost_impact)) as deleted_cost')
            )
            ->groupBy('products.category_id')
            ->get()
            ->keyBy('category_id');

        // Build the result set
        $result = collect();

        foreach ($categories as $category) {
            $inventory = $inventoryByCategory->get($category->id);
            $additions = $weeklyAdditions->get($category->id);
            $deletions = $weeklyDeletions->get($category->id);

            $totalStock = (int) ($inventory->total_stock ?? 0);
            $totalValue = (float) ($inventory->total_value ?? 0);
            $totalRetailValue = (float) ($inventory->total_retail_value ?? 0);
            $totalCostBasis = (float) ($inventory->total_cost_basis ?? 0);
            $projectedProfit = $totalRetailValue - $totalCostBasis;

            // Only include categories that have inventory or recent activity
            if ($totalStock > 0 || $additions || $deletions) {
                $result->push([
                    'category_id' => $category->id,
                    'parent_id' => $category->parent_id,
                    'category' => $category->name,
                    'total_stock' => $totalStock,
                    'total_value' => $totalValue,
                    'added_this_week' => (int) ($additions->added_count ?? 0),
                    'cost_added' => (float) ($additions->cost_added ?? 0),
                    'deleted_this_week' => (int) ($deletions->deleted_count ?? 0),
                    'deleted_cost' => (float) ($deletions->deleted_cost ?? 0),
                    'projected_profit' => $projectedProfit,
                ]);
            }
        }

        // Add "Uncategorized" for items without a category
        $uncategorizedInventory = DB::table('inventory')
            ->where('inventory.store_id', $storeId)
            ->join('product_variants', 'inventory.product_variant_id', '=', 'product_variants.id')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->whereNull('products.deleted_at')
            ->whereNull('products.category_id')
            ->select(
                DB::raw('SUM(inventory.quantity) as total_stock'),
                DB::raw('SUM(inventory.quantity * inventory.unit_cost) as total_value'),
                DB::raw('SUM(inventory.quantity * product_variants.price) as total_retail_value'),
                DB::raw('SUM(inventory.quantity * COALESCE(product_variants.wholesale_price, product_variants.cost, inventory.unit_cost, 0)) as total_cost_basis')
            )
            ->first();

        $uncategorizedAdditions = DB::table('inventory_adjustments')
            ->where('inventory_adjustments.store_id', $storeId)
            ->where('inventory_adjustments.created_at', '>=', $weekStart)
            ->where('inventory_adjustments.quantity_change', '>', 0)
            ->join('inventory', 'inventory_adjustments.inventory_id', '=', 'inventory.id')
            ->join('product_variants', 'inventory.product_variant_id', '=', 'product_variants.id')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->whereNull('products.deleted_at')
            ->whereNull('products.category_id')
            ->select(
                DB::raw('SUM(inventory_adjustments.quantity_change) as added_count'),
                DB::raw('SUM(inventory_adjustments.total_cost_impact) as cost_added')
            )
            ->first();

        $uncategorizedDeletions = DB::table('inventory_adjustments')
            ->where('inventory_adjustments.store_id', $storeId)
            ->where('inventory_adjustments.created_at', '>=', $weekStart)
            ->where('inventory_adjustments.quantity_change', '<', 0)
            ->join('inventory', 'inventory_adjustments.inventory_id', '=', 'inventory.id')
            ->join('product_variants', 'inventory.product_variant_id', '=', 'product_variants.id')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->whereNull('products.deleted_at')
            ->whereNull('products.category_id')
            ->select(
                DB::raw('SUM(ABS(inventory_adjustments.quantity_change)) as deleted_count'),
                DB::raw('SUM(ABS(inventory_adjustments.total_cost_impact)) as deleted_cost')
            )
            ->first();

        $uncategorizedStock = (int) ($uncategorizedInventory->total_stock ?? 0);
        if ($uncategorizedStock > 0 || ($uncategorizedAdditions->added_count ?? 0) > 0 || ($uncategorizedDeletions->deleted_count ?? 0) > 0) {
            $uncategorizedValue = (float) ($uncategorizedInventory->total_value ?? 0);
            $uncategorizedRetail = (float) ($uncategorizedInventory->total_retail_value ?? 0);
            $uncategorizedCostBasis = (float) ($uncategorizedInventory->total_cost_basis ?? 0);

            $result->push([
                'category_id' => null,
                'parent_id' => null,
                'category' => 'Uncategorized',
                'total_stock' => $uncategorizedStock,
                'total_value' => $uncategorizedValue,
                'added_this_week' => (int) ($uncategorizedAdditions->added_count ?? 0),
                'cost_added' => (float) ($uncategorizedAdditions->cost_added ?? 0),
                'deleted_this_week' => (int) ($uncategorizedDeletions->deleted_count ?? 0),
                'deleted_cost' => (float) ($uncategorizedDeletions->deleted_cost ?? 0),
                'projected_profit' => $uncategorizedRetail - $uncategorizedCostBasis,
            ]);
        }

        return $result->sortBy('category')->values();
    }

    /**
     * Get weekly trend data for the last 8 weeks.
     */
    protected function getWeeklyTrendData(int $storeId)
    {
        $weeks = collect();
        $current = now()->startOfWeek();

        for ($i = 7; $i >= 0; $i--) {
            $weekStart = $current->copy()->subWeeks($i);
            $weekEnd = $weekStart->copy()->endOfWeek();

            // Get inventory value at end of each week (approximation based on adjustments)
            $weeklyAdditions = DB::table('inventory_adjustments')
                ->where('store_id', $storeId)
                ->whereBetween('created_at', [$weekStart, $weekEnd])
                ->where('quantity_change', '>', 0)
                ->sum('total_cost_impact');

            $weeklyDeletions = DB::table('inventory_adjustments')
                ->where('store_id', $storeId)
                ->whereBetween('created_at', [$weekStart, $weekEnd])
                ->where('quantity_change', '<', 0)
                ->sum(DB::raw('ABS(total_cost_impact)'));

            $weeks->push([
                'week' => $weekStart->format('M d'),
                'added' => (float) $weeklyAdditions,
                'removed' => (float) $weeklyDeletions,
                'net' => (float) ($weeklyAdditions - $weeklyDeletions),
            ]);
        }

        return $weeks;
    }

    /**
     * Get week over week inventory data for a date range.
     */
    protected function getWeekOverWeekData(int $storeId, Carbon $rangeStart, Carbon $rangeEnd)
    {
        $weeks = collect();

        $weekStart = $rangeStart->copy()->startOfWeek();

        while ($weekStart->lte($rangeEnd)) {
            $weekEnd = $weekStart->copy()->endOfWeek();

            if ($weekEnd->gte($rangeStart) && $weekStart->lte($rangeEnd)) {
                $weeks->push($this->getWeekData($storeId, $weekStart, $weekEnd));
            }

            $weekStart->addWeek();
        }

        return $weeks->reverse()->values();
    }

    /**
     * Get inventory data for a specific week.
     */
    protected function getWeekData(int $storeId, Carbon $weekStart, Carbon $weekEnd): array
    {
        $additions = DB::table('inventory_adjustments')
            ->where('store_id', $storeId)
            ->whereBetween('created_at', [$weekStart, $weekEnd])
            ->where('quantity_change', '>', 0)
            ->selectRaw('COALESCE(SUM(quantity_change), 0) as items_added, COALESCE(SUM(total_cost_impact), 0) as cost_added')
            ->first();

        $deletions = DB::table('inventory_adjustments')
            ->where('store_id', $storeId)
            ->whereBetween('created_at', [$weekStart, $weekEnd])
            ->where('quantity_change', '<', 0)
            ->selectRaw('COALESCE(SUM(ABS(quantity_change)), 0) as items_removed, COALESCE(SUM(ABS(total_cost_impact)), 0) as cost_removed')
            ->first();

        $itemsAdded = (int) ($additions->items_added ?? 0);
        $costAdded = (float) ($additions->cost_added ?? 0);
        $itemsRemoved = (int) ($deletions->items_removed ?? 0);
        $costRemoved = (float) ($deletions->cost_removed ?? 0);

        return [
            'period' => $weekStart->format('M d').' - '.$weekEnd->format('M d, Y'),
            'week_start' => $weekStart->format('Y-m-d'),
            'items_added' => $itemsAdded,
            'cost_added' => $costAdded,
            'items_removed' => $itemsRemoved,
            'cost_removed' => $costRemoved,
            'net_items' => $itemsAdded - $itemsRemoved,
            'net_cost' => $costAdded - $costRemoved,
        ];
    }

    /**
     * Get month over month inventory data for a date range.
     */
    protected function getMonthOverMonthData(int $storeId, Carbon $rangeStart, Carbon $rangeEnd)
    {
        $months = collect();
        $current = $rangeStart->copy()->startOfMonth();

        while ($current->lte($rangeEnd)) {
            $monthStart = $current->copy();
            $monthEnd = $current->copy()->endOfMonth();
            $months->push($this->getMonthData($storeId, $monthStart, $monthEnd));
            $current->addMonth();
        }

        return $months->reverse()->values();
    }

    /**
     * Get inventory data for a specific month.
     */
    protected function getMonthData(int $storeId, Carbon $monthStart, Carbon $monthEnd): array
    {
        $additions = DB::table('inventory_adjustments')
            ->where('store_id', $storeId)
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->where('quantity_change', '>', 0)
            ->selectRaw('COALESCE(SUM(quantity_change), 0) as items_added, COALESCE(SUM(total_cost_impact), 0) as cost_added')
            ->first();

        $deletions = DB::table('inventory_adjustments')
            ->where('store_id', $storeId)
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->where('quantity_change', '<', 0)
            ->selectRaw('COALESCE(SUM(ABS(quantity_change)), 0) as items_removed, COALESCE(SUM(ABS(total_cost_impact)), 0) as cost_removed')
            ->first();

        $itemsAdded = (int) ($additions->items_added ?? 0);
        $costAdded = (float) ($additions->cost_added ?? 0);
        $itemsRemoved = (int) ($deletions->items_removed ?? 0);
        $costRemoved = (float) ($deletions->cost_removed ?? 0);

        return [
            'period' => $monthStart->format('M Y'),
            'month_start' => $monthStart->format('Y-m-d'),
            'month_key' => $monthStart->format('Y-m'), // For linking to weekly view
            'items_added' => $itemsAdded,
            'cost_added' => $costAdded,
            'items_removed' => $itemsRemoved,
            'cost_removed' => $costRemoved,
            'net_items' => $itemsAdded - $itemsRemoved,
            'net_cost' => $costAdded - $costRemoved,
        ];
    }

    /**
     * Get year over year inventory data for a year range.
     */
    protected function getYearOverYearData(int $storeId, int $startYear = 0, int $endYear = 0)
    {
        if ($startYear === 0) {
            $startYear = now()->year - 4;
        }
        if ($endYear === 0) {
            $endYear = now()->year;
        }

        $years = collect();

        for ($year = $startYear; $year <= $endYear; $year++) {
            $yearStart = Carbon::createFromDate($year, 1, 1)->startOfDay();
            $yearEnd = Carbon::createFromDate($year, 12, 31)->endOfDay();

            $additions = DB::table('inventory_adjustments')
                ->where('store_id', $storeId)
                ->whereBetween('created_at', [$yearStart, $yearEnd])
                ->where('quantity_change', '>', 0)
                ->selectRaw('COALESCE(SUM(quantity_change), 0) as items_added, COALESCE(SUM(total_cost_impact), 0) as cost_added')
                ->first();

            $deletions = DB::table('inventory_adjustments')
                ->where('store_id', $storeId)
                ->whereBetween('created_at', [$yearStart, $yearEnd])
                ->where('quantity_change', '<', 0)
                ->selectRaw('COALESCE(SUM(ABS(quantity_change)), 0) as items_removed, COALESCE(SUM(ABS(total_cost_impact)), 0) as cost_removed')
                ->first();

            $itemsAdded = (int) ($additions->items_added ?? 0);
            $costAdded = (float) ($additions->cost_added ?? 0);
            $itemsRemoved = (int) ($deletions->items_removed ?? 0);
            $costRemoved = (float) ($deletions->cost_removed ?? 0);

            $years->push([
                'period' => (string) $year,
                'year' => $year,
                'items_added' => $itemsAdded,
                'cost_added' => $costAdded,
                'items_removed' => $itemsRemoved,
                'cost_removed' => $costRemoved,
                'net_items' => $itemsAdded - $itemsRemoved,
                'net_cost' => $costAdded - $costRemoved,
            ]);
        }

        return $years->reverse()->values();
    }

    /**
     * Get daily inventory data for a date range.
     */
    protected function getDailyInventoryData(int $storeId, Carbon $rangeStart, Carbon $rangeEnd)
    {
        $days = collect();
        $current = $rangeStart->copy()->startOfDay();

        while ($current->lte($rangeEnd)) {
            $dayStart = $current->copy()->startOfDay();
            $dayEnd = $current->copy()->endOfDay();
            $days->push($this->getDayData($storeId, $dayStart, $dayEnd));
            $current->addDay();
        }

        return $days->reverse()->values();
    }

    /**
     * Get inventory data for a specific day.
     */
    protected function getDayData(int $storeId, Carbon $dayStart, Carbon $dayEnd): array
    {
        $additions = DB::table('inventory_adjustments')
            ->where('store_id', $storeId)
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->where('quantity_change', '>', 0)
            ->selectRaw('COALESCE(SUM(quantity_change), 0) as items_added, COALESCE(SUM(total_cost_impact), 0) as cost_added')
            ->first();

        $deletions = DB::table('inventory_adjustments')
            ->where('store_id', $storeId)
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->where('quantity_change', '<', 0)
            ->selectRaw('COALESCE(SUM(ABS(quantity_change)), 0) as items_removed, COALESCE(SUM(ABS(total_cost_impact)), 0) as cost_removed')
            ->first();

        $itemsAdded = (int) ($additions->items_added ?? 0);
        $costAdded = (float) ($additions->cost_added ?? 0);
        $itemsRemoved = (int) ($deletions->items_removed ?? 0);
        $costRemoved = (float) ($deletions->cost_removed ?? 0);

        return [
            'period' => $dayStart->format('M d, Y'),
            'date' => $dayStart->format('Y-m-d'),
            'items_added' => $itemsAdded,
            'cost_added' => $costAdded,
            'items_removed' => $itemsRemoved,
            'cost_removed' => $costRemoved,
            'net_items' => $itemsAdded - $itemsRemoved,
            'net_cost' => $costAdded - $costRemoved,
        ];
    }
}
