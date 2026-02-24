<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

class AlertNotificationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Creates notification templates and subscriptions for:
     * 1. Closed transaction (buy) deleted
     * 2. Closed order (sale) deleted
     * 3. Product price changed
     * 4. Manual inventory quantity adjustment
     */
    public function run(): void
    {
        $storeId = $this->command->ask('Enter store ID to seed alerts for', 3);

        $this->createClosedTransactionDeletedAlert((int) $storeId);
        $this->createClosedOrderDeletedAlert((int) $storeId);
        $this->createPriceChangedAlert((int) $storeId);
        $this->createInventoryAdjustedAlert((int) $storeId);

        $this->command->info('Alert notifications seeded successfully!');
    }

    protected function createClosedTransactionDeletedAlert(int $storeId): void
    {
        $template = NotificationTemplate::updateOrCreate(
            [
                'store_id' => $storeId,
                'slug' => 'alert-closed-transaction-deleted',
            ],
            [
                'name' => 'Alert: Closed Buy Transaction Deleted',
                'description' => 'Sent when a completed buy transaction is deleted',
                'channel' => NotificationChannel::TYPE_EMAIL,
                'subject' => 'ALERT: Closed Buy Transaction {{ transaction.transaction_number }} was deleted',
                'content' => $this->getTransactionDeletedTemplate(),
                'available_variables' => ['transaction', 'user', 'store', 'properties'],
                'category' => 'alerts',
                'is_enabled' => true,
            ]
        );

        NotificationSubscription::updateOrCreate(
            [
                'store_id' => $storeId,
                'activity' => Activity::TRANSACTIONS_DELETE_CLOSED,
            ],
            [
                'notification_template_id' => $template->id,
                'name' => 'Alert on Closed Buy Transaction Deletion',
                'description' => 'Sends an alert when a completed buy transaction is deleted',
                'recipients' => [['type' => NotificationSubscription::RECIPIENT_OWNER]],
                'schedule_type' => NotificationSubscription::SCHEDULE_IMMEDIATE,
                'is_enabled' => true,
            ]
        );

        $this->command->info("Created: Closed Transaction Deleted alert for store {$storeId}");
    }

    protected function createClosedOrderDeletedAlert(int $storeId): void
    {
        $template = NotificationTemplate::updateOrCreate(
            [
                'store_id' => $storeId,
                'slug' => 'alert-closed-order-deleted',
            ],
            [
                'name' => 'Alert: Closed Sale Deleted',
                'description' => 'Sent when a completed/shipped sale is deleted',
                'channel' => NotificationChannel::TYPE_EMAIL,
                'subject' => 'ALERT: Closed Sale {{ order.invoice_number|default("Order #" ~ order.id) }} was deleted',
                'content' => $this->getOrderDeletedTemplate(),
                'available_variables' => ['order', 'user', 'store', 'properties'],
                'category' => 'alerts',
                'is_enabled' => true,
            ]
        );

        NotificationSubscription::updateOrCreate(
            [
                'store_id' => $storeId,
                'activity' => Activity::ORDERS_DELETE_CLOSED,
            ],
            [
                'notification_template_id' => $template->id,
                'name' => 'Alert on Closed Sale Deletion',
                'description' => 'Sends an alert when a completed/shipped sale is deleted',
                'recipients' => [['type' => NotificationSubscription::RECIPIENT_OWNER]],
                'schedule_type' => NotificationSubscription::SCHEDULE_IMMEDIATE,
                'is_enabled' => true,
            ]
        );

        $this->command->info("Created: Closed Order Deleted alert for store {$storeId}");
    }

    protected function createPriceChangedAlert(int $storeId): void
    {
        $template = NotificationTemplate::updateOrCreate(
            [
                'store_id' => $storeId,
                'slug' => 'alert-price-changed',
            ],
            [
                'name' => 'Alert: Product Price Changed',
                'description' => 'Sent when a product price is changed after initial entry',
                'channel' => NotificationChannel::TYPE_EMAIL,
                'subject' => 'Price Change: {{ variant.sku|default(product.title) }}',
                'content' => $this->getPriceChangedTemplate(),
                'available_variables' => ['product', 'variant', 'user', 'store', 'properties'],
                'category' => 'alerts',
                'is_enabled' => true,
            ]
        );

        NotificationSubscription::updateOrCreate(
            [
                'store_id' => $storeId,
                'activity' => Activity::PRODUCTS_PRICE_CHANGE,
            ],
            [
                'notification_template_id' => $template->id,
                'name' => 'Alert on Product Price Changes',
                'description' => 'Sends an alert when product prices are changed',
                'recipients' => [['type' => NotificationSubscription::RECIPIENT_OWNER]],
                'schedule_type' => NotificationSubscription::SCHEDULE_IMMEDIATE,
                'is_enabled' => true,
            ]
        );

        $this->command->info("Created: Price Changed alert for store {$storeId}");
    }

    protected function createInventoryAdjustedAlert(int $storeId): void
    {
        $template = NotificationTemplate::updateOrCreate(
            [
                'store_id' => $storeId,
                'slug' => 'alert-inventory-adjusted',
            ],
            [
                'name' => 'Alert: Manual Inventory Adjustment',
                'description' => 'Sent when inventory quantity is manually adjusted (excludes automatic sales deductions)',
                'channel' => NotificationChannel::TYPE_EMAIL,
                'subject' => 'Inventory Adjusted: {{ variant.sku|default("Item #" ~ inventory.product_variant_id) }}',
                'content' => $this->getInventoryAdjustedTemplate(),
                'available_variables' => ['inventory', 'variant', 'product', 'warehouse', 'user', 'store', 'properties'],
                'category' => 'alerts',
                'is_enabled' => true,
            ]
        );

        NotificationSubscription::updateOrCreate(
            [
                'store_id' => $storeId,
                'activity' => Activity::INVENTORY_QUANTITY_MANUAL_ADJUST,
            ],
            [
                'notification_template_id' => $template->id,
                'name' => 'Alert on Manual Inventory Changes',
                'description' => 'Sends an alert when inventory is manually adjusted (not from sales)',
                'recipients' => [['type' => NotificationSubscription::RECIPIENT_OWNER]],
                'schedule_type' => NotificationSubscription::SCHEDULE_IMMEDIATE,
                'is_enabled' => true,
            ]
        );

        $this->command->info("Created: Manual Inventory Adjustment alert for store {$storeId}");
    }

    protected function getTransactionDeletedTemplate(): string
    {
        return <<<'HTML'
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 16px; margin-bottom: 20px;">
        <h2 style="color: #dc2626; margin: 0 0 8px;">⚠️ Closed Transaction Deleted</h2>
        <p style="color: #7f1d1d; margin: 0;">A completed buy transaction has been deleted from the system.</p>
    </div>

    <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600; width: 150px;">Transaction #:</td>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">{{ transaction.transaction_number }}</td>
        </tr>
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600;">Status:</td>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">{{ transaction.status }}</td>
        </tr>
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600;">Final Offer:</td>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">${{ transaction.final_offer|number_format(2) }}</td>
        </tr>
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600;">Deleted By:</td>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">{{ user.name|default('Unknown') }}</td>
        </tr>
        <tr>
            <td style="padding: 8px 0; font-weight: 600;">Deleted At:</td>
            <td style="padding: 8px 0;">{{ "now"|date("M j, Y g:i A") }}</td>
        </tr>
    </table>

    <p style="color: #6b7280; font-size: 12px; margin-top: 20px;">
        This is an automated alert from {{ store.name }}.
    </p>
</div>
HTML;
    }

    protected function getOrderDeletedTemplate(): string
    {
        return <<<'HTML'
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 16px; margin-bottom: 20px;">
        <h2 style="color: #dc2626; margin: 0 0 8px;">⚠️ Closed Sale Deleted</h2>
        <p style="color: #7f1d1d; margin: 0;">A completed sale has been deleted from the system.</p>
    </div>

    <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600; width: 150px;">Invoice #:</td>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">{{ order.invoice_number|default("Order #" ~ order.id) }}</td>
        </tr>
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600;">Status:</td>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">{{ order.status }}</td>
        </tr>
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600;">Total:</td>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">${{ order.total|number_format(2) }}</td>
        </tr>
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600;">Deleted By:</td>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">{{ user.name|default('Unknown') }}</td>
        </tr>
        <tr>
            <td style="padding: 8px 0; font-weight: 600;">Deleted At:</td>
            <td style="padding: 8px 0;">{{ "now"|date("M j, Y g:i A") }}</td>
        </tr>
    </table>

    <p style="color: #6b7280; font-size: 12px; margin-top: 20px;">
        This is an automated alert from {{ store.name }}.
    </p>
</div>
HTML;
    }

    protected function getPriceChangedTemplate(): string
    {
        return <<<'HTML'
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: #fefce8; border: 1px solid #fef08a; border-radius: 8px; padding: 16px; margin-bottom: 20px;">
        <h2 style="color: #a16207; margin: 0 0 8px;">💰 Price Changed</h2>
        <p style="color: #854d0e; margin: 0;">A product price has been updated.</p>
    </div>

    <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600; width: 150px;">Product:</td>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">{{ product.title|default('Unknown') }}</td>
        </tr>
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600;">SKU:</td>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">{{ variant.sku|default('N/A') }}</td>
        </tr>
        {% if properties.old.price is defined and properties.new.price is defined %}
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600;">Price:</td>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                <span style="text-decoration: line-through; color: #9ca3af;">${{ properties.old.price|number_format(2) }}</span>
                →
                <span style="color: #059669; font-weight: 600;">${{ properties.new.price|number_format(2) }}</span>
            </td>
        </tr>
        {% endif %}
        {% if properties.old.wholesale_price is defined and properties.new.wholesale_price is defined %}
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600;">Wholesale:</td>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                <span style="text-decoration: line-through; color: #9ca3af;">${{ properties.old.wholesale_price|number_format(2) }}</span>
                →
                <span style="color: #059669; font-weight: 600;">${{ properties.new.wholesale_price|number_format(2) }}</span>
            </td>
        </tr>
        {% endif %}
        {% if properties.old.cost is defined and properties.new.cost is defined %}
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600;">Cost:</td>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                <span style="text-decoration: line-through; color: #9ca3af;">${{ properties.old.cost|number_format(2) }}</span>
                →
                <span style="color: #059669; font-weight: 600;">${{ properties.new.cost|number_format(2) }}</span>
            </td>
        </tr>
        {% endif %}
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600;">Changed By:</td>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">{{ user.name|default('Unknown') }}</td>
        </tr>
        <tr>
            <td style="padding: 8px 0; font-weight: 600;">Changed At:</td>
            <td style="padding: 8px 0;">{{ "now"|date("M j, Y g:i A") }}</td>
        </tr>
    </table>

    <p style="color: #6b7280; font-size: 12px; margin-top: 20px;">
        This is an automated alert from {{ store.name }}.
    </p>
</div>
HTML;
    }

    protected function getInventoryAdjustedTemplate(): string
    {
        return <<<'HTML'
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 16px; margin-bottom: 20px;">
        <h2 style="color: #1d4ed8; margin: 0 0 8px;">📦 Inventory Adjusted</h2>
        <p style="color: #1e40af; margin: 0;">Inventory quantity has been manually adjusted.</p>
    </div>

    <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600; width: 150px;">Product:</td>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">{{ product.title|default('Unknown') }}</td>
        </tr>
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600;">SKU:</td>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">{{ variant.sku|default('N/A') }}</td>
        </tr>
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600;">Warehouse:</td>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">{{ warehouse.name|default('Default') }}</td>
        </tr>
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600;">Quantity Change:</td>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                {{ properties.old.quantity|default(0) }}
                →
                {{ properties.new.quantity|default(0) }}
                {% if properties.adjustment > 0 %}
                    <span style="color: #059669; font-weight: 600;">(+{{ properties.adjustment }})</span>
                {% else %}
                    <span style="color: #dc2626; font-weight: 600;">({{ properties.adjustment }})</span>
                {% endif %}
            </td>
        </tr>
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600;">Adjustment Type:</td>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">{{ properties.type|default('manual')|capitalize }}</td>
        </tr>
        {% if properties.reason %}
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600;">Reason:</td>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">{{ properties.reason }}</td>
        </tr>
        {% endif %}
        {% if properties.notes %}
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600;">Notes:</td>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">{{ properties.notes }}</td>
        </tr>
        {% endif %}
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600;">Adjusted By:</td>
            <td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">{{ user.name|default('Unknown') }}</td>
        </tr>
        <tr>
            <td style="padding: 8px 0; font-weight: 600;">Adjusted At:</td>
            <td style="padding: 8px 0;">{{ "now"|date("M j, Y g:i A") }}</td>
        </tr>
    </table>

    <p style="color: #6b7280; font-size: 12px; margin-top: 20px;">
        This is an automated alert from {{ store.name }}. This notification is only sent for manual adjustments, not automatic inventory changes from sales.
    </p>
</div>
HTML;
    }
}
