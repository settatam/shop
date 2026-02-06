<?php

namespace Tests\Unit\DynamicQuery;

use App\Services\DynamicQuery\QueryValidator;
use Tests\TestCase;

class QueryValidatorTest extends TestCase
{
    protected QueryValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new QueryValidator;
    }

    public function test_accepts_valid_select_query(): void
    {
        $sql = 'SELECT * FROM orders WHERE status = "completed"';
        $result = $this->validator->validate($sql, 1);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_accepts_select_with_joins(): void
    {
        $sql = 'SELECT o.id, c.name FROM orders o JOIN customers c ON o.customer_id = c.id';
        $result = $this->validator->validate($sql, 1);

        $this->assertTrue($result['valid']);
    }

    public function test_accepts_select_with_aggregations(): void
    {
        $sql = 'SELECT COUNT(*) as count, SUM(total) as revenue FROM orders GROUP BY status';
        $result = $this->validator->validate($sql, 1);

        $this->assertTrue($result['valid']);
    }

    public function test_rejects_insert_query(): void
    {
        $sql = 'INSERT INTO orders (customer_id, total) VALUES (1, 100)';
        $result = $this->validator->validate($sql, 1);

        $this->assertFalse($result['valid']);
        $this->assertContains('Dangerous keyword detected: INSERT', $result['errors']);
    }

    public function test_rejects_update_query(): void
    {
        $sql = 'UPDATE orders SET status = "cancelled" WHERE id = 1';
        $result = $this->validator->validate($sql, 1);

        $this->assertFalse($result['valid']);
        $this->assertContains('Dangerous keyword detected: UPDATE', $result['errors']);
    }

    public function test_rejects_delete_query(): void
    {
        $sql = 'DELETE FROM orders WHERE id = 1';
        $result = $this->validator->validate($sql, 1);

        $this->assertFalse($result['valid']);
        $this->assertContains('Dangerous keyword detected: DELETE', $result['errors']);
    }

    public function test_rejects_drop_table(): void
    {
        $sql = 'DROP TABLE orders';
        $result = $this->validator->validate($sql, 1);

        $this->assertFalse($result['valid']);
        $this->assertContains('Dangerous keyword detected: DROP', $result['errors']);
    }

    public function test_rejects_truncate(): void
    {
        $sql = 'TRUNCATE TABLE orders';
        $result = $this->validator->validate($sql, 1);

        $this->assertFalse($result['valid']);
        $this->assertContains('Dangerous keyword detected: TRUNCATE', $result['errors']);
    }

    public function test_rejects_alter_table(): void
    {
        $sql = 'ALTER TABLE orders ADD COLUMN hacked VARCHAR(255)';
        $result = $this->validator->validate($sql, 1);

        $this->assertFalse($result['valid']);
        $this->assertContains('Dangerous keyword detected: ALTER', $result['errors']);
    }

    public function test_rejects_sql_injection_with_union(): void
    {
        // Attempt union-based injection
        $sql = 'SELECT * FROM orders WHERE id = 1 UNION SELECT * FROM users';
        $result = $this->validator->validate($sql, 1);

        // Should reject because 'users' is not in allowed tables
        $this->assertFalse($result['valid']);
        $this->assertContains('Table not allowed: users', $result['errors']);
    }

    public function test_rejects_access_to_sensitive_tables(): void
    {
        $sql = 'SELECT * FROM users';
        $result = $this->validator->validate($sql, 1);

        $this->assertFalse($result['valid']);
        $this->assertContains('Table not allowed: users', $result['errors']);
    }

    public function test_rejects_password_reset_table(): void
    {
        $sql = 'SELECT * FROM password_resets';
        $result = $this->validator->validate($sql, 1);

        $this->assertFalse($result['valid']);
        $this->assertContains('Table not allowed: password_resets', $result['errors']);
    }

    public function test_rejects_sleep_injection(): void
    {
        $sql = 'SELECT * FROM orders WHERE id = 1 AND SLEEP(10)';
        $result = $this->validator->validate($sql, 1);

        $this->assertFalse($result['valid']);
        $this->assertContains('Dangerous keyword detected: SLEEP', $result['errors']);
    }

    public function test_rejects_benchmark_injection(): void
    {
        $sql = "SELECT * FROM orders WHERE BENCHMARK(1000000, SHA1('test'))";
        $result = $this->validator->validate($sql, 1);

        $this->assertFalse($result['valid']);
        $this->assertContains('Dangerous keyword detected: BENCHMARK', $result['errors']);
    }

    public function test_rejects_outfile_injection(): void
    {
        $sql = "SELECT * FROM orders INTO OUTFILE '/tmp/hacked.txt'";
        $result = $this->validator->validate($sql, 1);

        $this->assertFalse($result['valid']);
        $this->assertContains('Dangerous keyword detected: INTO OUTFILE', $result['errors']);
    }

    public function test_injects_store_id_when_missing(): void
    {
        $sql = 'SELECT * FROM orders WHERE status = "completed"';
        $result = $this->validator->validate($sql, 25);

        $this->assertTrue($result['valid']);
        $this->assertStringContainsString('store_id = 25', $result['sql']);
    }

    public function test_preserves_existing_store_id_placeholder(): void
    {
        $sql = 'SELECT * FROM orders WHERE store_id = ? AND status = "completed"';
        $result = $this->validator->validate($sql, 25);

        $this->assertTrue($result['valid']);
        $this->assertStringContainsString('store_id = 25', $result['sql']);
    }

    public function test_handles_comments_in_sql(): void
    {
        // Attempt to use comments to hide malicious code
        $sql = 'SELECT * FROM orders -- DROP TABLE orders';
        $result = $this->validator->validate($sql, 1);

        // Should pass because comment is stripped and it's just a SELECT
        $this->assertTrue($result['valid']);
    }

    public function test_handles_multiline_comments(): void
    {
        $sql = 'SELECT * FROM orders /* INSERT INTO users VALUES (1) */ WHERE id = 1';
        $result = $this->validator->validate($sql, 1);

        // Should pass because comment content is stripped
        $this->assertTrue($result['valid']);
    }

    public function test_rejects_cte_with_non_whitelisted_alias(): void
    {
        // CTE aliases are extracted as table names, so 'recent_orders' will fail
        // because it's not in the allowed tables whitelist
        $sql = 'WITH recent_orders AS (SELECT * FROM orders WHERE created_at > NOW() - INTERVAL 7 DAY) SELECT * FROM recent_orders';
        $result = $this->validator->validate($sql, 1);

        // This correctly rejects the query because 'recent_orders' is not a whitelisted table
        $this->assertFalse($result['valid']);
    }

    public function test_handles_subqueries(): void
    {
        $sql = 'SELECT * FROM orders WHERE customer_id IN (SELECT id FROM customers WHERE status = "active")';
        $result = $this->validator->validate($sql, 1);

        $this->assertTrue($result['valid']);
    }

    public function test_rejects_subquery_to_sensitive_table(): void
    {
        $sql = 'SELECT * FROM orders WHERE user_id IN (SELECT id FROM users WHERE admin = 1)';
        $result = $this->validator->validate($sql, 1);

        $this->assertFalse($result['valid']);
        $this->assertContains('Table not allowed: users', $result['errors']);
    }

    public function test_injects_store_id_before_group_by(): void
    {
        $sql = 'SELECT status, COUNT(*) FROM orders GROUP BY status';
        $result = $this->validator->validate($sql, 25);

        $this->assertTrue($result['valid']);
        $this->assertStringContainsString('store_id = 25', $result['sql']);
        // store_id should come before GROUP BY
        $this->assertMatchesRegularExpression('/store_id = 25.*GROUP BY/i', $result['sql']);
    }

    public function test_injects_store_id_before_order_by(): void
    {
        $sql = 'SELECT * FROM orders ORDER BY created_at DESC';
        $result = $this->validator->validate($sql, 25);

        $this->assertTrue($result['valid']);
        $this->assertStringContainsString('store_id = 25', $result['sql']);
        $this->assertMatchesRegularExpression('/store_id = 25.*ORDER BY/i', $result['sql']);
    }

    public function test_injects_store_id_before_limit(): void
    {
        $sql = 'SELECT * FROM orders LIMIT 10';
        $result = $this->validator->validate($sql, 25);

        $this->assertTrue($result['valid']);
        $this->assertStringContainsString('store_id = 25', $result['sql']);
        $this->assertMatchesRegularExpression('/store_id = 25.*LIMIT/i', $result['sql']);
    }
}
