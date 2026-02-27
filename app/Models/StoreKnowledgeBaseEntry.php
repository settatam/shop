<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreKnowledgeBaseEntry extends Model
{
    use BelongsToStore, HasFactory;

    public const TYPE_RETURN_POLICY = 'return_policy';

    public const TYPE_SHIPPING_INFO = 'shipping_info';

    public const TYPE_CARE_INSTRUCTIONS = 'care_instructions';

    public const TYPE_FAQ = 'faq';

    public const TYPE_ABOUT = 'about';

    public const TYPE_CUSTOM = 'custom';

    public const VALID_TYPES = [
        self::TYPE_RETURN_POLICY,
        self::TYPE_SHIPPING_INFO,
        self::TYPE_CARE_INSTRUCTIONS,
        self::TYPE_FAQ,
        self::TYPE_ABOUT,
        self::TYPE_CUSTOM,
    ];

    protected $fillable = [
        'store_id',
        'type',
        'title',
        'content',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get the human-readable type label.
     */
    public function getTypeLabelAttribute(): string
    {
        return static::getTypeLabel($this->type);
    }

    /**
     * Get the label for a given type string.
     */
    public static function getTypeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_RETURN_POLICY => 'Return Policy',
            self::TYPE_SHIPPING_INFO => 'Shipping Info',
            self::TYPE_CARE_INSTRUCTIONS => 'Care Instructions',
            self::TYPE_FAQ => 'FAQ',
            self::TYPE_ABOUT => 'About',
            self::TYPE_CUSTOM => 'Custom',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }
}
