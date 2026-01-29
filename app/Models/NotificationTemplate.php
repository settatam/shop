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
        'name',
        'slug',
        'description',
        'channel',
        'subject',
        'content',
        'available_variables',
        'category',
        'is_system',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'available_variables' => 'array',
            'is_system' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
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
