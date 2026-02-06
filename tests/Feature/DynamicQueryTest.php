<?php

namespace Tests\Feature;

use App\Services\DynamicQuery\QueryExecutor;
use App\Services\DynamicQuery\QueryValidator;
use App\Services\DynamicQuery\ReportFormatter;
use App\Services\DynamicQuery\SchemaProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DynamicQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_provider_returns_allowed_tables_only(): void
    {
        $schemaProvider = new SchemaProvider;
        $allowedTables = $schemaProvider->getAllowedTables();

        // Verify allowed tables are defined in config
        $this->assertNotEmpty($allowedTables);
        $this->assertContains('orders', $allowedTables);
        $this->assertContains('customers', $allowedTables);
        $this->assertNotContains('users', $allowedTables);
        $this->assertNotContains('password_reset_tokens', $allowedTables);
    }

    public function test_schema_provider_builds_schema_for_existing_tables(): void
    {
        $schemaProvider = new SchemaProvider;
        $schema = $schemaProvider->getSchema(1);

        // Should return empty or partial schema depending on which tables exist
        $this->assertIsArray($schema);

        // If orders table exists, check its schema structure
        if (Schema::hasTable('orders')) {
            $this->assertArrayHasKey('orders', $schema);
            $this->assertArrayHasKey('columns', $schema['orders']);
        }
    }

    public function test_query_validator_allows_simple_select(): void
    {
        $validator = new QueryValidator;
        $result = $validator->validate('SELECT * FROM orders WHERE status = "completed"', 1);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_query_validator_rejects_unauthorized_tables(): void
    {
        $validator = new QueryValidator;
        $result = $validator->validate('SELECT * FROM users', 1);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_query_validator_injects_store_id(): void
    {
        $validator = new QueryValidator;
        $result = $validator->validate('SELECT * FROM orders', 25);

        $this->assertTrue($result['valid']);
        $this->assertStringContainsString('store_id = 25', $result['sql']);
    }

    public function test_query_executor_enforces_limit(): void
    {
        $executor = new QueryExecutor;
        $sql = 'SELECT 1 as test';

        // Use reflection to test ensureLimit method
        $reflection = new \ReflectionClass($executor);
        $method = $reflection->getMethod('ensureLimit');
        $method->setAccessible(true);

        $limitedSql = $method->invoke($executor, $sql);
        $this->assertStringContainsString('LIMIT', $limitedSql);
    }

    public function test_query_executor_caps_existing_limit(): void
    {
        $executor = new QueryExecutor;
        $sql = 'SELECT 1 as test LIMIT 9999';

        $reflection = new \ReflectionClass($executor);
        $method = $reflection->getMethod('ensureLimit');
        $method->setAccessible(true);

        $maxRows = config('dynamic-query.limits.max_rows', 1000);
        $limitedSql = $method->invoke($executor, $sql);
        $this->assertStringContainsString("LIMIT {$maxRows}", $limitedSql);
    }

    public function test_report_formatter_formats_for_display(): void
    {
        $formatter = new ReportFormatter;
        $data = [
            ['id' => 1, 'name' => 'Test', 'total' => 100.50],
            ['id' => 2, 'name' => 'Test 2', 'total' => 200.75],
        ];

        $result = $formatter->format($data, ['id', 'name', 'total'], 'display');

        $this->assertEquals('display', $result['format']);
        $this->assertArrayHasKey('headers', $result['content']);
        $this->assertArrayHasKey('rows', $result['content']);
        $this->assertCount(2, $result['content']['rows']);
    }

    public function test_report_formatter_formats_for_voice(): void
    {
        $formatter = new ReportFormatter;
        $data = [
            ['id' => 1, 'name' => 'Test'],
        ];

        $result = $formatter->format($data, ['id', 'name'], 'voice');

        $this->assertEquals('voice', $result['format']);
        $this->assertIsString($result['content']);
        $this->assertStringContainsString('1 result', $result['content']);
    }

    public function test_report_formatter_generates_csv(): void
    {
        $formatter = new ReportFormatter;
        $data = [
            ['id' => 1, 'name' => 'Test'],
            ['id' => 2, 'name' => 'Test 2'],
        ];

        $result = $formatter->format($data, ['id', 'name'], 'csv');

        $this->assertEquals('csv', $result['format']);
        $this->assertIsString($result['content']);
        $this->assertStringContainsString('id', $result['content']);
        $this->assertStringContainsString('name', $result['content']);
    }

    public function test_report_formatter_handles_empty_data(): void
    {
        $formatter = new ReportFormatter;
        $result = $formatter->format([], [], 'display');

        $this->assertEquals('No results found', $result['summary']);
    }

    public function test_report_formatter_generates_html_table_for_email(): void
    {
        $formatter = new ReportFormatter;
        $data = [
            ['id' => 1, 'total' => 100],
        ];

        $result = $formatter->format($data, ['id', 'total'], 'email');

        $this->assertEquals('email', $result['format']);
        $this->assertArrayHasKey('html_table', $result['content']);
        $this->assertStringContainsString('<table', $result['content']['html_table']);
    }

    public function test_config_has_expected_values(): void
    {
        $allowedTables = config('dynamic-query.allowed_tables');
        $blockedColumns = config('dynamic-query.blocked_columns');
        $maxRows = config('dynamic-query.limits.max_rows');
        $timeout = config('dynamic-query.limits.query_timeout');

        $this->assertIsArray($allowedTables);
        $this->assertIsArray($blockedColumns);
        $this->assertIsInt($maxRows);
        $this->assertIsInt($timeout);
        $this->assertGreaterThan(0, $maxRows);
        $this->assertGreaterThan(0, $timeout);
    }

    public function test_blocked_columns_include_sensitive_fields(): void
    {
        $blockedColumns = config('dynamic-query.blocked_columns');

        $this->assertContains('password', $blockedColumns);
        $this->assertContains('remember_token', $blockedColumns);
        $this->assertContains('api_token', $blockedColumns);
    }
}
