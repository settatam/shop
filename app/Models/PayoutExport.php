<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoutExport extends Model
{
    use BelongsToStore;

    public const FORMAT_CSV = 'csv';

    public const FORMAT_EXCEL = 'excel';

    public const FORMAT_PAYPAL = 'paypal';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'store_id',
        'user_id',
        'filename',
        'format',
        'record_count',
        'filters',
        'date_from',
        'date_to',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'date_from' => 'date',
            'date_to' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, PayoutExport>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the download URL for this export.
     */
    public function getDownloadUrlAttribute(): string
    {
        return route('web.payout-exports.download', $this);
    }

    /**
     * Get available formats.
     *
     * @return array<string, string>
     */
    public static function getFormats(): array
    {
        return [
            self::FORMAT_CSV => 'CSV',
            self::FORMAT_EXCEL => 'Excel',
            self::FORMAT_PAYPAL => 'PayPal Batch',
        ];
    }
}
