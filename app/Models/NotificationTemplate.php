<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class NotificationTemplate extends Model
{
    use BelongsToStore, HasFactory, SoftDeletes;

    protected $fillable = [
        'store_id',
        'notification_layout_id',
        'name',
        'slug',
        'description',
        'channel',
        'subject',
        'content',
        'structure',
        'template_type',
        'available_variables',
        'category',
        'is_system',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'available_variables' => 'array',
            'structure' => 'array',
            'is_system' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function layout(): BelongsTo
    {
        return $this->belongsTo(NotificationLayout::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(NotificationSubscription::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }

    /**
     * Render the template content with the given data using Twig.
     */
    public function render(array $data = []): string
    {
        return self::renderTwig($this->content, $data);
    }

    /**
     * Render the subject with the given data using Twig.
     */
    public function renderSubject(array $data = []): string
    {
        if (! $this->subject) {
            return '';
        }

        return self::renderTwig($this->subject, $data);
    }

    /**
     * Render a Twig template string with data.
     */
    public static function renderTwig(string $template, array $data = []): string
    {
        try {
            $loader = new ArrayLoader(['template' => $template]);
            $twig = new Environment($loader, [
                'autoescape' => 'html',
            ]);

            // Add some useful filters/functions
            $twig->addFilter(new \Twig\TwigFilter('money', fn ($value) => number_format((float) $value, 2)));
            $twig->addFilter(new \Twig\TwigFilter('date_format', fn ($value, $format = 'M d, Y') => $value instanceof \DateTimeInterface
                ? $value->format($format)
                : date($format, strtotime($value))
            ));

            return $twig->render('template', $data);
        } catch (\Exception $e) {
            report($e);

            return $template; // Return original if rendering fails
        }
    }

    /**
     * Wrap rendered body HTML in a layout.
     *
     * Resolves the layout from the database when a template is provided,
     * falling back to the hardcoded default email layout.
     */
    public static function renderWithLayout(
        string $body,
        array $store,
        ?NotificationLayout $layout = null,
        ?self $template = null,
    ): string {
        if (! $layout && $template) {
            $layout = NotificationLayout::resolveForTemplate($template);
        }

        if ($layout) {
            return $layout->render($body, $store);
        }

        // Fallback: hardcoded layout for backward compatibility
        return self::renderTwig(NotificationLayout::getDefaultEmailLayoutContent(), [
            'body' => $body,
            'store' => $store,
        ]);
    }

    /**
     * Get default templates for common activities.
     */
    public static function getDefaultTemplates(): array
    {
        return [
            // Order notifications
            [
                'slug' => 'order-created',
                'name' => 'Order Created',
                'channel' => NotificationChannel::TYPE_EMAIL,
                'category' => 'orders',
                'subject' => 'New Order #{{ order.number }} Received',
                'content' => '<h2>New Order Received</h2>
<p>A new order has been placed.</p>
<p><strong>Order:</strong> #{{ order.number }}</p>
<p><strong>Customer:</strong> {{ customer.name }}</p>
<p><strong>Total:</strong> ${{ order.total|money }}</p>
<p><strong>Items:</strong></p>
<ul>
{% for item in order.items %}
    <li>{{ item.name }} x {{ item.quantity }} - ${{ item.total|money }}</li>
{% endfor %}
</ul>',
                'available_variables' => ['order', 'customer', 'store'],
                'is_system' => true,
            ],
            [
                'slug' => 'order-fulfilled',
                'name' => 'Order Fulfilled',
                'channel' => NotificationChannel::TYPE_EMAIL,
                'category' => 'orders',
                'subject' => 'Your Order #{{ order.number }} Has Shipped',
                'content' => '<h2>Your Order Has Shipped!</h2>
<p>Great news! Your order #{{ order.number }} is on its way.</p>
{% if order.tracking_number %}
<p><strong>Tracking Number:</strong> {{ order.tracking_number }}</p>
{% endif %}
<p>Thank you for shopping with us!</p>',
                'available_variables' => ['order', 'customer', 'store'],
                'is_system' => true,
            ],

            [
                'slug' => 'order-completed',
                'name' => 'Order Completed',
                'channel' => NotificationChannel::TYPE_EMAIL,
                'category' => 'orders',
                'subject' => 'Your Order #{{ order.number }} is Complete',
                'content' => '<h2>Order Complete</h2>
<p>Hi {{ customer.name }},</p>
<p>Your order <strong>#{{ order.number }}</strong> has been completed.</p>
<p><strong>Order Summary:</strong></p>
<table style="width:100%;border-collapse:collapse">
<thead><tr>
<th style="text-align:left;padding:8px;border-bottom:1px solid #e5e7eb">Item</th>
<th style="text-align:right;padding:8px;border-bottom:1px solid #e5e7eb">Qty</th>
<th style="text-align:right;padding:8px;border-bottom:1px solid #e5e7eb">Price</th>
</tr></thead>
<tbody>
{% for item in order.items %}
<tr>
<td style="padding:8px;border-bottom:1px solid #f3f4f6">{{ item.name }}</td>
<td style="text-align:right;padding:8px;border-bottom:1px solid #f3f4f6">{{ item.quantity }}</td>
<td style="text-align:right;padding:8px;border-bottom:1px solid #f3f4f6">${{ item.total|money }}</td>
</tr>
{% endfor %}
</tbody>
</table>
<p style="margin-top:16px"><strong>Total:</strong> ${{ order.total|money }}</p>
<p>Thank you for your purchase from {{ store.name }}!</p>',
                'available_variables' => ['order', 'customer', 'store'],
                'is_system' => true,
            ],
            [
                'slug' => 'order-cancelled',
                'name' => 'Order Cancelled',
                'channel' => NotificationChannel::TYPE_EMAIL,
                'category' => 'orders',
                'subject' => 'Order #{{ order.number }} Has Been Cancelled',
                'content' => '<h2>Order Cancelled</h2>
<p>Order <strong>#{{ order.number }}</strong> has been cancelled.</p>
<p><strong>Customer:</strong> {{ customer.name|default("N/A") }}</p>
<p><strong>Total:</strong> ${{ order.total|money }}</p>
<p><strong>Cancelled By:</strong> {{ user.name|default("Unknown") }}</p>',
                'available_variables' => ['order', 'customer', 'user', 'store'],
                'is_system' => true,
            ],

            // Product notifications
            [
                'slug' => 'product-created',
                'name' => 'Product Created',
                'channel' => NotificationChannel::TYPE_EMAIL,
                'category' => 'products',
                'subject' => 'New Product Added: {{ product.title }}',
                'content' => '<h2>New Product Added</h2>
<p>A new product has been added to your catalog.</p>
<p><strong>Title:</strong> {{ product.title }}</p>
<p><strong>SKU:</strong> {{ product.sku }}</p>
<p><strong>Price:</strong> ${{ product.price|money }}</p>',
                'available_variables' => ['product', 'user', 'store'],
                'is_system' => true,
            ],
            [
                'slug' => 'product-updated',
                'name' => 'Product Updated',
                'channel' => NotificationChannel::TYPE_EMAIL,
                'category' => 'products',
                'subject' => 'Product Updated: {{ product.title }}',
                'content' => '<h2>Product Updated</h2>
<p>A product in your catalog has been updated.</p>
<p><strong>Product:</strong> {{ product.title }}</p>
<p><strong>SKU:</strong> {{ product.sku|default("N/A") }}</p>
<p><strong>Updated By:</strong> {{ user.name|default("Unknown") }}</p>',
                'available_variables' => ['product', 'user', 'store', 'properties'],
                'is_system' => true,
            ],

            // Listing notifications
            [
                'slug' => 'listing-published',
                'name' => 'Product Published to Platform',
                'channel' => NotificationChannel::TYPE_EMAIL,
                'category' => 'listings',
                'subject' => 'Product Published: {{ product.title|default("Item") }}',
                'content' => '<h2>Product Published</h2>
<p>A product has been published to an external platform.</p>
<p><strong>Product:</strong> {{ product.title|default("Unknown") }}</p>
<p><strong>Platform:</strong> {{ listing.platform|default("Unknown") }}</p>
<p><strong>Published By:</strong> {{ user.name|default("Unknown") }}</p>',
                'available_variables' => ['product', 'listing', 'user', 'store'],
                'is_system' => true,
            ],

            // Transaction (Buy) notifications
            [
                'slug' => 'transaction-created',
                'name' => 'Buy Transaction Created',
                'channel' => NotificationChannel::TYPE_EMAIL,
                'category' => 'transactions',
                'subject' => 'New Buy Transaction #{{ transaction.transaction_number }}',
                'content' => '<h2>New Buy Transaction</h2>
<p>A new buy transaction has been created.</p>
<p><strong>Transaction #:</strong> {{ transaction.transaction_number }}</p>
<p><strong>Customer:</strong> {{ customer.name|default("Walk-in") }}</p>
<p><strong>Total:</strong> ${{ transaction.final_offer|default(transaction.total)|money }}</p>
<p><strong>Created By:</strong> {{ user.name|default("Unknown") }}</p>',
                'available_variables' => ['transaction', 'customer', 'user', 'store'],
                'is_system' => true,
            ],

            // Inventory notifications
            [
                'slug' => 'low-stock-alert',
                'name' => 'Low Stock Alert',
                'channel' => NotificationChannel::TYPE_EMAIL,
                'category' => 'inventory',
                'subject' => 'Low Stock Alert: {{ product.title }}',
                'content' => '<h2>Low Stock Alert</h2>
<p>The following product is running low on stock:</p>
<p><strong>Product:</strong> {{ product.title }}</p>
<p><strong>SKU:</strong> {{ product.sku }}</p>
<p><strong>Current Stock:</strong> {{ inventory.quantity }}</p>
<p><strong>Reorder Point:</strong> {{ inventory.reorder_point }}</p>',
                'available_variables' => ['product', 'inventory', 'warehouse', 'store'],
                'is_system' => true,
            ],

            // Customer notifications
            [
                'slug' => 'customer-welcome',
                'name' => 'Customer Welcome',
                'channel' => NotificationChannel::TYPE_EMAIL,
                'category' => 'customers',
                'subject' => 'Welcome to {{ store.name }}!',
                'content' => '<h2>Welcome, {{ customer.name }}!</h2>
<p>Thank you for creating an account with {{ store.name }}.</p>
<p>We\'re excited to have you as a customer!</p>',
                'available_variables' => ['customer', 'store'],
                'is_system' => true,
            ],

            [
                'slug' => 'chat-lead-captured',
                'name' => 'Chat Lead Captured',
                'channel' => NotificationChannel::TYPE_EMAIL,
                'category' => 'customers',
                'subject' => 'New Chat Lead: {{ customer.name }}',
                'content' => '<h2>New Lead from Storefront Chat</h2>
<p>A visitor to your online store shared their contact information during a chat conversation.</p>
<table style="width:100%;border-collapse:collapse;font-size:14px">
<tr><td style="padding:8px 0;border-bottom:1px solid #e5e7eb;font-weight:600;width:140px">Name:</td><td style="padding:8px 0;border-bottom:1px solid #e5e7eb">{{ customer.name }}</td></tr>
{% if customer.email %}<tr><td style="padding:8px 0;border-bottom:1px solid #e5e7eb;font-weight:600">Email:</td><td style="padding:8px 0;border-bottom:1px solid #e5e7eb">{{ customer.email }}</td></tr>{% endif %}
{% if customer.phone %}<tr><td style="padding:8px 0;border-bottom:1px solid #e5e7eb;font-weight:600">Phone:</td><td style="padding:8px 0;border-bottom:1px solid #e5e7eb">{{ customer.phone }}</td></tr>{% endif %}
{% if properties.interest %}<tr><td style="padding:8px 0;border-bottom:1px solid #e5e7eb;font-weight:600">Interested In:</td><td style="padding:8px 0;border-bottom:1px solid #e5e7eb">{{ properties.interest }}</td></tr>{% endif %}
<tr><td style="padding:8px 0;font-weight:600">Source:</td><td style="padding:8px 0">Storefront Chat</td></tr>
</table>
<p style="margin-top:16px">Follow up with this lead to convert their interest into a sale!</p>',
                'available_variables' => ['customer', 'store', 'properties'],
                'is_system' => true,
            ],

            // Team notifications
            [
                'slug' => 'team-invite',
                'name' => 'Team Member Invitation',
                'channel' => NotificationChannel::TYPE_EMAIL,
                'category' => 'team',
                'subject' => "You've been invited to join {{ store.name }}",
                'content' => '<h2>Team Invitation</h2>
<p>You\'ve been invited to join {{ store.name }} as a team member.</p>
<p><strong>Role:</strong> {{ role.name }}</p>
<p>Click the link below to accept your invitation and set up your account.</p>',
                'available_variables' => ['user', 'role', 'store', 'invite_url'],
                'is_system' => true,
            ],

            // Alert notifications
            [
                'slug' => 'alert-price-changed',
                'name' => 'Alert: Product Price Changed',
                'channel' => NotificationChannel::TYPE_EMAIL,
                'category' => 'alerts',
                'subject' => 'Price Change: {{ variant.sku|default(product.title) }}',
                'content' => '<div style="background: #fefce8; border: 1px solid #fef08a; border-radius: 8px; padding: 16px; margin-bottom: 20px;">
    <h2 style="color: #a16207; margin: 0 0 8px;">Price Changed</h2>
    <p style="color: #854d0e; margin: 0;">A product price has been updated.</p>
</div>
<table style="width: 100%; border-collapse: collapse; font-size: 14px;">
    <tr><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600; width: 150px;">Product:</td><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">{{ product.title|default("Unknown") }}</td></tr>
    <tr><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600;">SKU:</td><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">{{ variant.sku|default("N/A") }}</td></tr>
    {% if properties.old.price is defined and properties.new.price is defined %}
    <tr><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600;">Price:</td><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;"><span style="text-decoration: line-through; color: #9ca3af;">${{ properties.old.price|number_format(2) }}</span> → <span style="color: #059669; font-weight: 600;">${{ properties.new.price|number_format(2) }}</span></td></tr>
    {% endif %}
    {% if properties.old.wholesale_price is defined and properties.new.wholesale_price is defined %}
    <tr><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600;">Wholesale:</td><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;"><span style="text-decoration: line-through; color: #9ca3af;">${{ properties.old.wholesale_price|number_format(2) }}</span> → <span style="color: #059669; font-weight: 600;">${{ properties.new.wholesale_price|number_format(2) }}</span></td></tr>
    {% endif %}
    {% if properties.old.cost is defined and properties.new.cost is defined %}
    <tr><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600;">Cost:</td><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;"><span style="text-decoration: line-through; color: #9ca3af;">${{ properties.old.cost|number_format(2) }}</span> → <span style="color: #059669; font-weight: 600;">${{ properties.new.cost|number_format(2) }}</span></td></tr>
    {% endif %}
    <tr><td style="padding: 8px 0; font-weight: 600;">Changed By:</td><td style="padding: 8px 0;">{{ user.name|default("Unknown") }}</td></tr>
</table>',
                'available_variables' => ['product', 'variant', 'user', 'store', 'properties'],
                'is_system' => true,
            ],
            [
                'slug' => 'alert-inventory-adjusted',
                'name' => 'Alert: Manual Inventory Adjustment',
                'channel' => NotificationChannel::TYPE_EMAIL,
                'category' => 'alerts',
                'subject' => 'Inventory Adjusted: {{ variant.sku|default("Item") }}',
                'content' => '<div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 16px; margin-bottom: 20px;">
    <h2 style="color: #1d4ed8; margin: 0 0 8px;">Inventory Adjusted</h2>
    <p style="color: #1e40af; margin: 0;">Inventory quantity has been manually adjusted.</p>
</div>
<table style="width: 100%; border-collapse: collapse; font-size: 14px;">
    <tr><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600; width: 150px;">Product:</td><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">{{ product.title|default("Unknown") }}</td></tr>
    <tr><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600;">SKU:</td><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">{{ variant.sku|default("N/A") }}</td></tr>
    <tr><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600;">Warehouse:</td><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">{{ warehouse.name|default("Default") }}</td></tr>
    <tr><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600;">Quantity:</td><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">{{ properties.old.quantity|default(0) }} → {{ properties.new.quantity|default(0) }}</td></tr>
    {% if properties.reason %}<tr><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600;">Reason:</td><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">{{ properties.reason }}</td></tr>{% endif %}
    <tr><td style="padding: 8px 0; font-weight: 600;">Adjusted By:</td><td style="padding: 8px 0;">{{ user.name|default("Unknown") }}</td></tr>
</table>',
                'available_variables' => ['inventory', 'variant', 'product', 'warehouse', 'user', 'store', 'properties'],
                'is_system' => true,
            ],
            [
                'slug' => 'alert-closed-order-deleted',
                'name' => 'Alert: Closed Sale Deleted',
                'channel' => NotificationChannel::TYPE_EMAIL,
                'category' => 'alerts',
                'subject' => 'ALERT: Closed Sale #{{ order.invoice_number|default(order.id) }} was deleted',
                'content' => '<div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 16px; margin-bottom: 20px;">
    <h2 style="color: #dc2626; margin: 0 0 8px;">Closed Sale Deleted</h2>
    <p style="color: #7f1d1d; margin: 0;">A completed sale has been deleted from the system.</p>
</div>
<table style="width: 100%; border-collapse: collapse; font-size: 14px;">
    <tr><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600; width: 150px;">Invoice #:</td><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">{{ order.invoice_number|default("Order #" ~ order.id) }}</td></tr>
    <tr><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600;">Total:</td><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">${{ order.total|number_format(2) }}</td></tr>
    <tr><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600;">Deleted By:</td><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">{{ user.name|default("Unknown") }}</td></tr>
</table>',
                'available_variables' => ['order', 'user', 'store', 'properties'],
                'is_system' => true,
            ],
            [
                'slug' => 'alert-closed-transaction-deleted',
                'name' => 'Alert: Closed Buy Transaction Deleted',
                'channel' => NotificationChannel::TYPE_EMAIL,
                'category' => 'alerts',
                'subject' => 'ALERT: Closed Buy Transaction #{{ transaction.transaction_number }} was deleted',
                'content' => '<div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 16px; margin-bottom: 20px;">
    <h2 style="color: #dc2626; margin: 0 0 8px;">Closed Transaction Deleted</h2>
    <p style="color: #7f1d1d; margin: 0;">A completed buy transaction has been deleted from the system.</p>
</div>
<table style="width: 100%; border-collapse: collapse; font-size: 14px;">
    <tr><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600; width: 150px;">Transaction #:</td><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">{{ transaction.transaction_number }}</td></tr>
    <tr><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600;">Final Offer:</td><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">${{ transaction.final_offer|number_format(2) }}</td></tr>
    <tr><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: 600;">Deleted By:</td><td style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">{{ user.name|default("Unknown") }}</td></tr>
</table>',
                'available_variables' => ['transaction', 'user', 'store', 'properties'],
                'is_system' => true,
            ],

            // SMS Templates
            [
                'slug' => 'order-created-sms',
                'name' => 'Order Created (SMS)',
                'channel' => NotificationChannel::TYPE_SMS,
                'category' => 'orders',
                'subject' => null,
                'content' => 'New order #{{ order.number }} received for ${{ order.total|money }}. Customer: {{ customer.name }}',
                'available_variables' => ['order', 'customer', 'store'],
                'is_system' => true,
            ],
            [
                'slug' => 'order-completed-sms',
                'name' => 'Order Completed (SMS)',
                'channel' => NotificationChannel::TYPE_SMS,
                'category' => 'orders',
                'subject' => null,
                'content' => 'Your order #{{ order.number }} is complete! Total: ${{ order.total|money }}. Thank you for shopping with {{ store.name }}!',
                'available_variables' => ['order', 'customer', 'store'],
                'is_system' => true,
            ],
            [
                'slug' => 'low-stock-sms',
                'name' => 'Low Stock Alert (SMS)',
                'channel' => NotificationChannel::TYPE_SMS,
                'category' => 'inventory',
                'subject' => null,
                'content' => 'LOW STOCK: {{ product.title }} ({{ product.sku }}) - Only {{ inventory.quantity }} left',
                'available_variables' => ['product', 'inventory', 'store'],
                'is_system' => true,
            ],
        ];
    }

    /**
     * Create default templates for a store.
     */
    public static function createDefaultTemplates(int $storeId): void
    {
        foreach (self::getDefaultTemplates() as $template) {
            self::firstOrCreate(
                [
                    'store_id' => $storeId,
                    'slug' => $template['slug'],
                    'channel' => $template['channel'],
                ],
                array_merge($template, ['store_id' => $storeId])
            );
        }
    }

    /**
     * Scope to get templates by channel.
     */
    public function scopeForChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    /**
     * Scope to get templates by category.
     */
    public function scopeForCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope to get enabled templates.
     */
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }
}
