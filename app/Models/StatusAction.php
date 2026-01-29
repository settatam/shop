<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatusAction extends Model
{
    use HasFactory;

    // Action types
    public const TYPE_CHANGE_STATUS = 'change_status';

    public const TYPE_PRINT_SHIPPING_LABEL = 'print_shipping_label';

    public const TYPE_PRINT_BARCODE = 'print_barcode';

    public const TYPE_PRINT_RETURN_LABEL = 'print_return_label';

    public const TYPE_DELETE = 'delete';

    public const TYPE_EXPORT = 'export';

    public const TYPE_ADD_TAG = 'add_tag';

    public const TYPE_REMOVE_TAG = 'remove_tag';

    public const TYPE_ASSIGN_USER = 'assign_user';

    public const TYPE_CUSTOM = 'custom';

    protected $fillable = [
        'status_id',
        'action_type',
        'name',
        'icon',
        'color',
        'config',
        'is_bulk',
        'requires_confirmation',
        'confirmation_message',
        'sort_order',
        'is_enabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config' => 'array',
            'is_bulk' => 'boolean',
            'requires_confirmation' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * Get the status this action belongs to.
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    /**
     * Get all available action types with labels.
     *
     * @return array<string, string>
     */
    public static function getActionTypes(): array
    {
        return [
            self::TYPE_CHANGE_STATUS => 'Change Status',
            self::TYPE_PRINT_SHIPPING_LABEL => 'Print Shipping Label',
            self::TYPE_PRINT_BARCODE => 'Print Barcode',
            self::TYPE_PRINT_RETURN_LABEL => 'Print Return Label',
            self::TYPE_DELETE => 'Delete',
            self::TYPE_EXPORT => 'Export',
            self::TYPE_ADD_TAG => 'Add Tag',
            self::TYPE_REMOVE_TAG => 'Remove Tag',
            self::TYPE_ASSIGN_USER => 'Assign User',
            self::TYPE_CUSTOM => 'Custom Action',
        ];
    }

    /**
     * Get the target status for change_status actions.
     */
    public function getTargetStatus(): ?Status
    {
        if ($this->action_type !== self::TYPE_CHANGE_STATUS) {
            return null;
        }

        $targetStatusId = $this->config['target_status_id'] ?? null;
        if (! $targetStatusId) {
            return null;
        }

        return Status::find($targetStatusId);
    }

    /**
     * Get the tag for add_tag/remove_tag actions.
     */
    public function getTag(): ?Tag
    {
        if (! in_array($this->action_type, [self::TYPE_ADD_TAG, self::TYPE_REMOVE_TAG])) {
            return null;
        }

        $tagId = $this->config['tag_id'] ?? null;
        if (! $tagId) {
            return null;
        }

        return Tag::find($tagId);
    }

    /**
     * Scope to get enabled actions.
     */
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Scope to get bulk actions.
     */
    public function scopeBulk($query)
    {
        return $query->where('is_bulk', true);
    }

    /**
     * Scope ordered by sort_order.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
