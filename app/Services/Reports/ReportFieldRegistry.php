<?php

namespace App\Services\Reports;

use App\Models\Store;
use Illuminate\Support\Facades\DB;

/**
 * Maps natural language field names to database fields/expressions.
 *
 * This registry allows users to say "customer name" and the system
 * knows to use `customers.full_name` or `transactions.customer_name`.
 */
class ReportFieldRegistry
{
    /**
     * Core field mappings organized by context/entity.
     * Each entry: 'natural_name' => ['field' => 'db.field', 'label' => 'Display Label', 'type' => 'string|number|date|currency']
     */
    protected array $coreFields = [
        // Order/Sale fields
        'order_number' => ['field' => 'orders.order_number', 'label' => 'Order #', 'type' => 'string', 'aliases' => ['order id', 'sale number', 'invoice number']],
        'order_date' => ['field' => 'orders.created_at', 'label' => 'Date', 'type' => 'date', 'aliases' => ['sale date', 'date sold', 'transaction date']],
        'order_total' => ['field' => 'orders.total', 'label' => 'Total', 'type' => 'currency', 'aliases' => ['sale total', 'total sale', 'amount', 'total amount']],
        'order_subtotal' => ['field' => 'orders.subtotal', 'label' => 'Subtotal', 'type' => 'currency', 'aliases' => ['subtotal']],
        'order_tax' => ['field' => 'orders.tax', 'label' => 'Tax', 'type' => 'currency', 'aliases' => ['tax amount', 'sales tax']],
        'order_status' => ['field' => 'orders.status', 'label' => 'Status', 'type' => 'string', 'aliases' => ['sale status', 'order state']],
        'order_count' => ['field' => 'COUNT(orders.id)', 'label' => 'Sales #', 'type' => 'number', 'aliases' => ['number of sales', 'sales count', 'total orders']],

        // Customer fields
        'customer_name' => ['field' => 'customers.full_name', 'label' => 'Customer', 'type' => 'string', 'aliases' => ['customer', 'client name', 'buyer name', 'client']],
        'customer_email' => ['field' => 'customers.email', 'label' => 'Email', 'type' => 'string', 'aliases' => ['email', 'customer email']],
        'customer_phone' => ['field' => 'customers.phone', 'label' => 'Phone', 'type' => 'string', 'aliases' => ['phone', 'customer phone', 'phone number']],

        // Transaction (Buy) fields
        'transaction_id' => ['field' => 'transactions.id', 'label' => 'Transaction #', 'type' => 'string', 'aliases' => ['transaction number', 'buy id', 'purchase id']],
        'transaction_date' => ['field' => 'transactions.created_at', 'label' => 'Date', 'type' => 'date', 'aliases' => ['buy date', 'purchase date']],
        'bought_amount' => ['field' => 'transactions.total', 'label' => 'Bought', 'type' => 'currency', 'aliases' => ['bought', 'purchase amount', 'buy amount', 'amount bought']],
        'transaction_profit' => ['field' => 'transactions.profit', 'label' => 'Profit', 'type' => 'currency', 'aliases' => ['profit', 'profit margin', 'margin']],
        'payment_type' => ['field' => 'transactions.payment_type', 'label' => 'Payment Type', 'type' => 'string', 'aliases' => ['payment method', 'pay type', 'how paid']],
        'transaction_count' => ['field' => 'COUNT(transactions.id)', 'label' => 'Transactions', 'type' => 'number', 'aliases' => ['number of transactions', 'buy count', 'total buys']],

        // Product fields
        'product_title' => ['field' => 'products.title', 'label' => 'Product', 'type' => 'string', 'aliases' => ['product name', 'item name', 'item', 'product']],
        'product_sku' => ['field' => 'products.sku', 'label' => 'SKU', 'type' => 'string', 'aliases' => ['sku', 'item number', 'stock number']],
        'product_price' => ['field' => 'products.price', 'label' => 'Price', 'type' => 'currency', 'aliases' => ['price', 'selling price', 'list price']],
        'product_cost' => ['field' => 'products.cost', 'label' => 'Cost', 'type' => 'currency', 'aliases' => ['cost', 'item cost', 'purchase cost']],
        'items_sold' => ['field' => 'SUM(order_items.quantity)', 'label' => 'Items Sold', 'type' => 'number', 'aliases' => ['quantity sold', 'units sold', 'total items']],

        // Memo fields
        'memo_number' => ['field' => 'memos.memo_number', 'label' => 'Memo #', 'type' => 'string', 'aliases' => ['memo id', 'consignment number']],
        'memo_status' => ['field' => 'memos.status', 'label' => 'Status', 'type' => 'string', 'aliases' => ['memo status']],
        'memo_value' => ['field' => 'memos.total_value', 'label' => 'Value', 'type' => 'currency', 'aliases' => ['memo value', 'consignment value']],

        // Repair fields
        'repair_number' => ['field' => 'repairs.repair_number', 'label' => 'Repair #', 'type' => 'string', 'aliases' => ['repair id', 'job number']],
        'repair_status' => ['field' => 'repairs.status', 'label' => 'Status', 'type' => 'string', 'aliases' => ['repair status', 'job status']],
        'repair_cost' => ['field' => 'repairs.total_cost', 'label' => 'Cost', 'type' => 'currency', 'aliases' => ['repair cost', 'job cost']],

        // Vendor fields
        'vendor_name' => ['field' => 'vendors.name', 'label' => 'Vendor', 'type' => 'string', 'aliases' => ['vendor', 'supplier', 'supplier name']],

        // Store fields
        'store_name' => ['field' => 'stores.name', 'label' => 'Store', 'type' => 'string', 'aliases' => ['store', 'location']],

        // Aggregates
        'total_sales' => ['field' => 'SUM(orders.total)', 'label' => 'Total Sales', 'type' => 'currency', 'aliases' => ['sales total', 'total revenue', 'revenue']],
        'total_bought' => ['field' => 'SUM(transactions.total)', 'label' => 'Total Bought', 'type' => 'currency', 'aliases' => ['total purchases', 'total purchased']],
        'total_profit' => ['field' => 'SUM(transactions.profit)', 'label' => 'Total Profit', 'type' => 'currency', 'aliases' => ['profit total', 'gross profit']],
        'total_cost' => ['field' => 'SUM(products.cost)', 'label' => 'Total Cost', 'type' => 'currency', 'aliases' => ['cost total']],
    ];

    /**
     * Store-specific custom field mappings (loaded from database).
     */
    protected array $customFields = [];

    protected ?int $storeId = null;

    public function __construct(?int $storeId = null)
    {
        $this->storeId = $storeId;
        if ($storeId) {
            $this->loadCustomFields($storeId);
        }
    }

    /**
     * Load custom field mappings from the database for a store.
     */
    protected function loadCustomFields(int $storeId): void
    {
        // Custom fields could be stored in a report_field_mappings table
        // For now, we'll support this via store settings or a future table
        $this->customFields = [];
    }

    /**
     * Find a field by natural language name.
     */
    public function findField(string $naturalName): ?array
    {
        $normalized = $this->normalize($naturalName);

        // Check exact match first
        if (isset($this->coreFields[$normalized])) {
            return array_merge(['key' => $normalized], $this->coreFields[$normalized]);
        }

        // Check aliases
        foreach ($this->coreFields as $key => $field) {
            $aliases = $field['aliases'] ?? [];
            foreach ($aliases as $alias) {
                if ($this->normalize($alias) === $normalized) {
                    return array_merge(['key' => $key], $field);
                }
            }
        }

        // Check custom fields
        if (isset($this->customFields[$normalized])) {
            return array_merge(['key' => $normalized], $this->customFields[$normalized]);
        }

        return null;
    }

    /**
     * Find multiple fields from natural language descriptions.
     */
    public function findFields(array $naturalNames): array
    {
        $found = [];
        foreach ($naturalNames as $name) {
            $field = $this->findField($name);
            if ($field) {
                $found[] = $field;
            }
        }

        return $found;
    }

    /**
     * Get all available fields for AI context.
     */
    public function getAllFields(): array
    {
        $all = [];
        foreach ($this->coreFields as $key => $field) {
            $all[$key] = [
                'key' => $key,
                'label' => $field['label'],
                'type' => $field['type'],
                'aliases' => $field['aliases'] ?? [],
            ];
        }

        return $all;
    }

    /**
     * Get fields grouped by category for display.
     */
    public function getFieldsByCategory(): array
    {
        return [
            'Sales/Orders' => ['order_number', 'order_date', 'order_total', 'order_subtotal', 'order_tax', 'order_status', 'order_count', 'total_sales', 'items_sold'],
            'Customers' => ['customer_name', 'customer_email', 'customer_phone'],
            'Transactions (Buy)' => ['transaction_id', 'transaction_date', 'bought_amount', 'transaction_profit', 'payment_type', 'transaction_count', 'total_bought', 'total_profit'],
            'Products' => ['product_title', 'product_sku', 'product_price', 'product_cost', 'total_cost'],
            'Memos' => ['memo_number', 'memo_status', 'memo_value'],
            'Repairs' => ['repair_number', 'repair_status', 'repair_cost'],
            'Vendors' => ['vendor_name'],
        ];
    }

    /**
     * Get a summary for AI prompts.
     */
    public function getAISummary(): string
    {
        $summary = "AVAILABLE FIELDS (user can reference by name or alias):\n\n";

        foreach ($this->getFieldsByCategory() as $category => $fieldKeys) {
            $summary .= "{$category}:\n";
            foreach ($fieldKeys as $key) {
                if (isset($this->coreFields[$key])) {
                    $field = $this->coreFields[$key];
                    $aliases = implode(', ', $field['aliases'] ?? []);
                    $summary .= "  - {$field['label']} (key: {$key}, type: {$field['type']}";
                    if ($aliases) {
                        $summary .= ", also called: {$aliases}";
                    }
                    $summary .= ")\n";
                }
            }
            $summary .= "\n";
        }

        return $summary;
    }

    /**
     * Normalize a string for comparison.
     */
    protected function normalize(string $str): string
    {
        return strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $str), '_'));
    }
}
