<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\StoreContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryReportController extends Controller
{
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
        // Total inventory
        $inventory = DB::table('inventory')
            ->where('inventory.store_id', $storeId)
            ->join('product_variants', 'inventory.product_variant_id', '=', 'product_variants.id')
            ->selectRaw('
                COALESCE(SUM(inventory.quantity), 0) as total_stock,
                COALESCE(SUM(inventory.quantity * inventory.unit_cost), 0) as total_value,
                COALESCE(SUM(inventory.quantity * COALESCE(product_variants.wholesale_price, product_variants.price, 0)), 0) as total_wholesale
            ')
            ->first();

        // Weekly additions
        $additions = DB::table('inventory_adjustments')
            ->where('store_id', $storeId)
            ->where('created_at', '>=', $weekStart)
            ->where('quantity_change', '>', 0)
            ->selectRaw('COALESCE(SUM(quantity_change), 0) as added, COALESCE(SUM(total_cost_impact), 0) as cost_added')
            ->first();

        // Weekly deletions
        $deletions = DB::table('inventory_adjustments')
            ->where('store_id', $storeId)
            ->where('created_at', '>=', $weekStart)
            ->where('quantity_change', '<', 0)
            ->selectRaw('COALESCE(SUM(ABS(quantity_change)), 0) as deleted, COALESCE(SUM(ABS(total_cost_impact)), 0) as cost_deleted')
            ->first();

        $totalValue = (float) ($inventory->total_value ?? 0);
        $totalWholesale = (float) ($inventory->total_wholesale ?? 0);

        return [
            'total_stock' => (int) ($inventory->total_stock ?? 0),
            'total_value' => $totalValue,
            'added_this_week' => (int) ($additions->added ?? 0),
            'cost_added' => (float) ($additions->cost_added ?? 0),
            'deleted_this_week' => (int) ($deletions->deleted ?? 0),
            'deleted_cost' => (float) ($deletions->cost_deleted ?? 0),
            'projected_profit' => $totalWholesale - $totalValue,
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
            ->whereIn('products.category_id', $categoryIds)
            ->selectRaw('
                COALESCE(SUM(inventory.quantity), 0) as total_stock,
                COALESCE(SUM(inventory.quantity * inventory.unit_cost), 0) as total_value,
                COALESCE(SUM(inventory.quantity * COALESCE(product_variants.wholesale_price, product_variants.price, 0)), 0) as total_wholesale
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
            ->whereIn('products.category_id', $categoryIds)
            ->selectRaw('COALESCE(SUM(ABS(inventory_adjustments.quantity_change)), 0) as deleted, COALESCE(SUM(ABS(inventory_adjustments.total_cost_impact)), 0) as cost_deleted')
            ->first();

        $totalValue = (float) ($inventory->total_value ?? 0);
        $totalWholesale = (float) ($inventory->total_wholesale ?? 0);

        return [
            'total_stock' => (int) ($inventory->total_stock ?? 0),
            'total_value' => $totalValue,
            'added_this_week' => (int) ($additions->added ?? 0),
            'cost_added' => (float) ($additions->cost_added ?? 0),
            'deleted_this_week' => (int) ($deletions->deleted ?? 0),
            'deleted_cost' => (float) ($deletions->cost_deleted ?? 0),
            'projected_profit' => $totalWholesale - $totalValue,
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
            ->whereNull('products.category_id')
            ->selectRaw('
                COALESCE(SUM(inventory.quantity), 0) as total_stock,
                COALESCE(SUM(inventory.quantity * inventory.unit_cost), 0) as total_value,
                COALESCE(SUM(inventory.quantity * COALESCE(product_variants.wholesale_price, product_variants.price, 0)), 0) as total_wholesale
            ')
            ->first();

        $additions = DB::table('inventory_adjustments')
            ->where('inventory_adjustments.store_id', $storeId)
            ->where('inventory_adjustments.created_at', '>=', $weekStart)
            ->where('inventory_adjustments.quantity_change', '>', 0)
            ->join('inventory', 'inventory_adjustments.inventory_id', '=', 'inventory.id')
            ->join('product_variants', 'inventory.product_variant_id', '=', 'product_variants.id')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
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
            ->whereNull('products.category_id')
            ->selectRaw('COALESCE(SUM(ABS(inventory_adjustments.quantity_change)), 0) as deleted, COALESCE(SUM(ABS(inventory_adjustments.total_cost_impact)), 0) as cost_deleted')
            ->first();

        $totalValue = (float) ($inventory->total_value ?? 0);
        $totalWholesale = (float) ($inventory->total_wholesale ?? 0);

        return [
            'total_stock' => (int) ($inventory->total_stock ?? 0),
            'total_value' => $totalValue,
            'added_this_week' => (int) ($additions->added ?? 0),
            'cost_added' => (float) ($additions->cost_added ?? 0),
            'deleted_this_week' => (int) ($deletions->deleted ?? 0),
            'deleted_cost' => (float) ($deletions->cost_deleted ?? 0),
            'projected_profit' => $totalWholesale - $totalValue,
        ];
    }

    /**
     * Week over week inventory report.
     * Supports filtering by month (e.g., ?month=2024-01).
     */
    public function weekly(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $month = $request->query('month'); // Format: YYYY-MM

        // Get weekly data (filtered by month if provided)
        $weeklyData = $this->getWeekOverWeekData($store->id, $month);

        // Calculate totals
        $totals = [
            'items_added' => $weeklyData->sum('items_added'),
            'cost_added' => $weeklyData->sum('cost_added'),
            'items_removed' => $weeklyData->sum('items_removed'),
            'cost_removed' => $weeklyData->sum('cost_removed'),
            'net_items' => $weeklyData->sum('net_items'),
            'net_cost' => $weeklyData->sum('net_cost'),
        ];

        // Build filter info for display
        $filterInfo = null;
        if ($month) {
            $filterInfo = [
                'type' => 'month',
                'value' => $month,
                'label' => Carbon::parse($month.'-01')->format('F Y'),
            ];
        }

        return Inertia::render('reports/inventory/Weekly', [
            'weeklyData' => $weeklyData,
            'totals' => $totals,
            'filter' => $filterInfo,
        ]);
    }

    /**
     * Month over month inventory report.
     * Supports filtering by year (e.g., ?year=2024).
     */
    public function monthly(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $year = $request->query('year'); // Format: YYYY

        // Get monthly data (filtered by year if provided)
        $monthlyData = $this->getMonthOverMonthData($store->id, $year);

        // Calculate totals
        $totals = [
            'items_added' => $monthlyData->sum('items_added'),
            'cost_added' => $monthlyData->sum('cost_added'),
            'items_removed' => $monthlyData->sum('items_removed'),
            'cost_removed' => $monthlyData->sum('cost_removed'),
            'net_items' => $monthlyData->sum('net_items'),
            'net_cost' => $monthlyData->sum('net_cost'),
        ];

        // Build filter info for display
        $filterInfo = null;
        if ($year) {
            $filterInfo = [
                'type' => 'year',
                'value' => $year,
                'label' => $year,
            ];
        }

        return Inertia::render('reports/inventory/Monthly', [
            'monthlyData' => $monthlyData,
            'totals' => $totals,
            'filter' => $filterInfo,
        ]);
    }

    /**
     * Year over year inventory report.
     */
    public function yearly(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();

        // Get past 5 years of data
        $yearlyData = $this->getYearOverYearData($store->id);

        // Calculate totals
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
        $weeklyData = $this->getWeekOverWeekData($store->id);

        return $this->exportPeriodData($weeklyData, 'inventory-weekly-'.now()->format('Y-m-d').'.csv', 'Week');
    }

    /**
     * Export monthly report to CSV.
     */
    public function exportMonthly(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $monthlyData = $this->getMonthOverMonthData($store->id);

        return $this->exportPeriodData($monthlyData, 'inventory-monthly-'.now()->format('Y-m-d').'.csv', 'Month');
    }

    /**
     * Export yearly report to CSV.
     */
    public function exportYearly(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $yearlyData = $this->getYearOverYearData($store->id);

        return $this->exportPeriodData($yearlyData, 'inventory-yearly-'.now()->format('Y-m-d').'.csv', 'Year');
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
            ->select(
                'products.category_id',
                DB::raw('SUM(inventory.quantity) as total_stock'),
                DB::raw('SUM(inventory.quantity * inventory.unit_cost) as total_value'),
                DB::raw('SUM(inventory.quantity * COALESCE(product_variants.wholesale_price, product_variants.price, 0)) as total_wholesale_value')
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
            $totalWholesaleValue = (float) ($inventory->total_wholesale_value ?? 0);
            $projectedProfit = $totalWholesaleValue - $totalValue;

            // Only include categories that have inventory or recent activity
            if ($totalStock > 0 || $additions || $deletions) {
                $result->push([
                    'category_id' => $category->id,
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
            ->whereNull('products.category_id')
            ->select(
                DB::raw('SUM(inventory.quantity) as total_stock'),
                DB::raw('SUM(inventory.quantity * inventory.unit_cost) as total_value'),
                DB::raw('SUM(inventory.quantity * COALESCE(product_variants.wholesale_price, product_variants.price, 0)) as total_wholesale_value')
            )
            ->first();

        $uncategorizedAdditions = DB::table('inventory_adjustments')
            ->where('inventory_adjustments.store_id', $storeId)
            ->where('inventory_adjustments.created_at', '>=', $weekStart)
            ->where('inventory_adjustments.quantity_change', '>', 0)
            ->join('inventory', 'inventory_adjustments.inventory_id', '=', 'inventory.id')
            ->join('product_variants', 'inventory.product_variant_id', '=', 'product_variants.id')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
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
            ->whereNull('products.category_id')
            ->select(
                DB::raw('SUM(ABS(inventory_adjustments.quantity_change)) as deleted_count'),
                DB::raw('SUM(ABS(inventory_adjustments.total_cost_impact)) as deleted_cost')
            )
            ->first();

        $uncategorizedStock = (int) ($uncategorizedInventory->total_stock ?? 0);
        if ($uncategorizedStock > 0 || ($uncategorizedAdditions->added_count ?? 0) > 0 || ($uncategorizedDeletions->deleted_count ?? 0) > 0) {
            $uncategorizedValue = (float) ($uncategorizedInventory->total_value ?? 0);
            $uncategorizedWholesale = (float) ($uncategorizedInventory->total_wholesale_value ?? 0);

            $result->push([
                'category_id' => null,
                'category' => 'Uncategorized',
                'total_stock' => $uncategorizedStock,
                'total_value' => $uncategorizedValue,
                'added_this_week' => (int) ($uncategorizedAdditions->added_count ?? 0),
                'cost_added' => (float) ($uncategorizedAdditions->cost_added ?? 0),
                'deleted_this_week' => (int) ($uncategorizedDeletions->deleted_count ?? 0),
                'deleted_cost' => (float) ($uncategorizedDeletions->deleted_cost ?? 0),
                'projected_profit' => $uncategorizedWholesale - $uncategorizedValue,
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
     * Get week over week inventory data.
     * If month is provided (YYYY-MM), shows weeks within that month.
     * Otherwise shows past 13 weeks.
     */
    protected function getWeekOverWeekData(int $storeId, ?string $month = null)
    {
        $weeks = collect();

        if ($month) {
            // Get all weeks within the specified month
            $monthStart = Carbon::parse($month.'-01')->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();

            // Start from the first week that contains days from this month
            $weekStart = $monthStart->copy()->startOfWeek();

            while ($weekStart->lte($monthEnd)) {
                $weekEnd = $weekStart->copy()->endOfWeek();

                // Only include weeks that overlap with the month
                if ($weekEnd->gte($monthStart) && $weekStart->lte($monthEnd)) {
                    $weeks->push($this->getWeekData($storeId, $weekStart, $weekEnd));
                }

                $weekStart->addWeek();
            }
        } else {
            // Default: past 13 weeks
            $current = now()->startOfWeek();

            for ($i = 12; $i >= 0; $i--) {
                $weekStart = $current->copy()->subWeeks($i);
                $weekEnd = $weekStart->copy()->endOfWeek();
                $weeks->push($this->getWeekData($storeId, $weekStart, $weekEnd));
            }
        }

        return $weeks;
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
     * Get month over month inventory data.
     * If year is provided, shows all 12 months of that year.
     * Otherwise shows past 13 months.
     */
    protected function getMonthOverMonthData(int $storeId, ?string $year = null)
    {
        $months = collect();

        if ($year) {
            // Get all 12 months of the specified year
            for ($m = 1; $m <= 12; $m++) {
                $monthStart = Carbon::createFromDate((int) $year, $m, 1)->startOfDay();
                $monthEnd = $monthStart->copy()->endOfMonth();
                $months->push($this->getMonthData($storeId, $monthStart, $monthEnd));
            }
        } else {
            // Default: past 13 months
            $current = now()->startOfMonth();

            for ($i = 12; $i >= 0; $i--) {
                $monthStart = $current->copy()->subMonths($i);
                $monthEnd = $monthStart->copy()->endOfMonth();
                $months->push($this->getMonthData($storeId, $monthStart, $monthEnd));
            }
        }

        return $months;
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
     * Get year over year inventory data (past 5 years).
     */
    protected function getYearOverYearData(int $storeId)
    {
        $years = collect();
        $currentYear = now()->year;

        for ($i = 4; $i >= 0; $i--) {
            $year = $currentYear - $i;
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

        return $years;
    }
}
