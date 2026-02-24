<?php

namespace Tests\Feature\Reports;

use App\Models\Store;
use App\Services\Reports\Email\DailySalesReport;
use App\Services\Reports\Email\LegacyBuyReport;
use App\Services\Reports\Email\LegacySalesReport;
use App\Services\Reports\ReportRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected ReportRegistry $registry;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new ReportRegistry;
        $this->store = Store::factory()->create();
    }

    public function test_can_get_available_reports(): void
    {
        $reports = $this->registry->getAvailableReports();

        $this->assertTrue($reports->count() >= 2, 'Should have at least 2 report classes');

        // Check that expected reports exist
        $types = $reports->pluck('type')->toArray();
        $this->assertContains('daily_sales', $types);
        $this->assertContains('daily_buy', $types);
    }

    public function test_can_get_dropdown_options(): void
    {
        $options = $this->registry->getDropdownOptions();

        $this->assertIsArray($options);
        $this->assertNotEmpty($options);

        // Each option should have value, label, description, slug
        foreach ($options as $option) {
            $this->assertArrayHasKey('value', $option);
            $this->assertArrayHasKey('label', $option);
            $this->assertArrayHasKey('slug', $option);
        }
    }

    public function test_can_get_report_by_type(): void
    {
        $class = $this->registry->getReportByType('daily_sales');

        $this->assertNotNull($class);
        $this->assertEquals(DailySalesReport::class, $class);
    }

    public function test_can_get_report_by_slug(): void
    {
        $class = $this->registry->getReportBySlug('daily-sales-report');

        $this->assertNotNull($class);
        $this->assertEquals(DailySalesReport::class, $class);
    }

    public function test_can_instantiate_report(): void
    {
        $report = $this->registry->makeReport('daily_sales', $this->store);

        $this->assertNotNull($report);
        $this->assertInstanceOf(DailySalesReport::class, $report);
        $this->assertEquals('daily_sales', $report->getType());
        $this->assertEquals('Daily Sales Report', $report->getName());
    }

    public function test_report_returns_valid_structure(): void
    {
        $report = $this->registry->makeReport('daily_sales', $this->store);
        $structure = $report->getStructure();

        $this->assertNotNull($structure);

        $array = $structure->toArray();
        $this->assertArrayHasKey('tables', $array);
        $this->assertNotEmpty($array['tables']);

        // Should have 3 tables for daily sales
        $this->assertCount(3, $array['tables']);
    }

    public function test_report_structure_has_typed_columns(): void
    {
        $report = $this->registry->makeReport('daily_sales', $this->store);
        $structure = $report->getStructure()->toArray();

        $dailyOrdersTable = $structure['tables'][0];
        $this->assertEquals('daily_orders', $dailyOrdersTable['name']);

        // Check column types
        $columns = collect($dailyOrdersTable['columns']);

        // Should have currency columns
        $currencyColumns = $columns->where('type', 'currency');
        $this->assertTrue($currencyColumns->count() >= 1, 'Should have at least one currency column');

        // Should have link column
        $linkColumns = $columns->where('type', 'link');
        $this->assertTrue($linkColumns->count() >= 1, 'Should have at least one link column');

        // Should have badge column
        $badgeColumns = $columns->where('type', 'badge');
        $this->assertTrue($badgeColumns->count() >= 1, 'Should have at least one badge column');
    }

    public function test_returns_null_for_unknown_report(): void
    {
        $class = $this->registry->getReportByType('unknown_report');
        $this->assertNull($class);

        $report = $this->registry->makeReport('unknown_report', $this->store);
        $this->assertNull($report);
    }

    public function test_exists_returns_correct_values(): void
    {
        $this->assertTrue($this->registry->exists('daily_sales'));
        $this->assertTrue($this->registry->exists('daily_buy'));
        $this->assertFalse($this->registry->exists('unknown_report'));
    }

    public function test_legacy_sales_report_is_registered(): void
    {
        $class = $this->registry->getReportByType('legacy_daily_sales');

        $this->assertNotNull($class);
        $this->assertEquals(LegacySalesReport::class, $class);
    }

    public function test_legacy_buy_report_is_registered(): void
    {
        $class = $this->registry->getReportByType('legacy_daily_buy');

        $this->assertNotNull($class);
        $this->assertEquals(LegacyBuyReport::class, $class);
    }

    public function test_legacy_sales_report_structure(): void
    {
        $report = $this->registry->makeReport('legacy_daily_sales', $this->store);

        $this->assertNotNull($report);
        $this->assertInstanceOf(LegacySalesReport::class, $report);

        $structure = $report->getStructure()->toArray();

        // Should have 3 tables
        $this->assertCount(3, $structure['tables']);

        // First table should be daily_sales
        $this->assertEquals('daily_sales', $structure['tables'][0]['name']);

        // Should have lead column (legacy specific)
        $columns = collect($structure['tables'][0]['columns']);
        $leadColumn = $columns->firstWhere('key', 'lead');
        $this->assertNotNull($leadColumn, 'Should have lead column for legacy reports');

        // Should have service_fee column in monthly summary (matching SalesReportController)
        $monthlyColumns = collect($structure['tables'][1]['columns']);
        $serviceFeeColumn = $monthlyColumns->firstWhere('key', 'total_service_fee');
        $this->assertNotNull($serviceFeeColumn, 'Should have service fee column');
    }

    public function test_legacy_buy_report_structure(): void
    {
        $report = $this->registry->makeReport('legacy_daily_buy', $this->store);

        $this->assertNotNull($report);
        $this->assertInstanceOf(LegacyBuyReport::class, $report);

        $structure = $report->getStructure()->toArray();

        // Should have 3 tables
        $this->assertCount(3, $structure['tables']);

        // First table should be daily_buys
        $this->assertEquals('daily_buys', $structure['tables'][0]['name']);

        // Should have buy-specific columns (matching BuysReportController)
        $columns = collect($structure['tables'][0]['columns']);

        $purchaseColumn = $columns->firstWhere('key', 'purchase_amt');
        $this->assertNotNull($purchaseColumn, 'Should have purchase_amt column');
        $this->assertEquals('currency', $purchaseColumn['type']);

        $estimatedColumn = $columns->firstWhere('key', 'estimated_value');
        $this->assertNotNull($estimatedColumn, 'Should have estimated_value column');

        $profitPctColumn = $columns->firstWhere('key', 'profit_pct');
        $this->assertNotNull($profitPctColumn, 'Should have profit_pct column');
        $this->assertEquals('percentage', $profitPctColumn['type']);
    }

    public function test_all_report_types_are_available(): void
    {
        $reports = $this->registry->getAvailableReports();
        $types = $reports->pluck('type')->toArray();

        // All expected report types
        $expectedTypes = [
            'daily_sales',
            'daily_buy',
            'legacy_daily_sales',
            'legacy_daily_buy',
        ];

        foreach ($expectedTypes as $type) {
            $this->assertContains($type, $types, "Missing report type: {$type}");
        }
    }
}
