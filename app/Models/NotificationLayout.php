<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class NotificationLayout extends Model
{
    use BelongsToStore, HasFactory, SoftDeletes;

    protected $fillable = [
        'store_id',
        'name',
        'slug',
        'channel',
        'content',
        'description',
        'is_default',
        'is_system',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_system' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(NotificationTemplate::class);
    }

    /**
     * Render the layout with the given body content and store data.
     */
    public function render(string $body, array $storeData = []): string
    {
        return NotificationTemplate::renderTwig($this->content, [
            'body' => $body,
            'store' => $storeData,
        ]);
    }

    /**
     * Resolve the layout for a given template.
     *
     * Priority:
     * 1. Template's explicitly assigned layout (via FK)
     * 2. Store's default layout for that channel
     * 3. null (caller uses hardcoded fallback or sends body as-is)
     */
    public static function resolveForTemplate(NotificationTemplate $template): ?self
    {
        if ($template->notification_layout_id) {
            $layout = self::withoutGlobalScopes()
                ->where('id', $template->notification_layout_id)
                ->first();

            if ($layout && $layout->is_enabled) {
                return $layout;
            }
        }

        return self::withoutGlobalScopes()
            ->where('store_id', $template->store_id)
            ->where('channel', $template->channel)
            ->where('is_default', true)
            ->where('is_enabled', true)
            ->first();
    }

    /**
     * Get default layout definitions.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getDefaultLayouts(): array
    {
        return [
            [
                'slug' => 'default-email',
                'name' => 'Default Email Layout',
                'channel' => NotificationChannel::TYPE_EMAIL,
                'description' => 'Standard email layout with logo header, content area, and store info footer.',
                'content' => self::getDefaultEmailLayoutContent(),
                'is_default' => true,
                'is_system' => true,
            ],
            [
                'slug' => 'default-sms',
                'name' => 'Default SMS Layout',
                'channel' => NotificationChannel::TYPE_SMS,
                'description' => 'Simple SMS wrapper that appends store name.',
                'content' => '{{ body|raw }} - {{ store.name }}',
                'is_default' => true,
                'is_system' => true,
            ],
        ];
    }

    /**
     * Create default layouts for a store.
     */
    public static function createDefaultLayouts(int $storeId): void
    {
        foreach (self::getDefaultLayouts() as $layout) {
            self::firstOrCreate(
                [
                    'store_id' => $storeId,
                    'slug' => $layout['slug'],
                    'channel' => $layout['channel'],
                ],
                array_merge($layout, ['store_id' => $storeId])
            );
        }
    }

    /**
     * Get the default email layout Twig content.
     */
    public static function getDefaultEmailLayoutContent(): string
    {
        return <<<'TWIG'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ store.name }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #1f2937;
            margin: 0;
            padding: 0;
            background-color: #f3f4f6;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .card {
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 32px;
            margin-top: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 24px;
        }
        .header img {
            max-height: 60px;
            margin-bottom: 16px;
        }
        .footer {
            text-align: center;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid #e5e7eb;
            color: #6b7280;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            {% if store.logo %}
            <div class="header">
                <img src="{{ store.logo }}" alt="{{ store.name }}">
            </div>
            {% endif %}
            {{ body|raw }}
            <div class="footer">
                <p>{{ store.name }}</p>
                {% if store.full_address %}<p>{{ store.full_address }}</p>{% endif %}
                {% if store.phone %}<p>{{ store.phone }}</p>{% endif %}
            </div>
        </div>
    </div>
</body>
</html>
TWIG;
    }

    public function scopeForChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }
}
