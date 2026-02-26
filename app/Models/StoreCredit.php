<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StoreCredit extends Model
{
    /** @use HasFactory<\Database\Factories\StoreCreditFactory> */
    use BelongsToStore, HasFactory;

    public const TYPE_CREDIT = 'credit';

    public const TYPE_DEBIT = 'debit';

    public const SOURCE_BUY_TRANSACTION = 'buy_transaction';

    public const SOURCE_ORDER_PAYMENT = 'order_payment';

    public const SOURCE_CASH_OUT = 'cash_out';

    public const SOURCE_REFUND = 'refund';

    public const SOURCE_MANUAL = 'manual';

    public const PAYOUT_CASH = 'cash';

    public const PAYOUT_CHECK = 'check';

    public const PAYOUT_PAYPAL = 'paypal';

    public const PAYOUT_VENMO = 'venmo';

    public const PAYOUT_ACH = 'ach';

    public const PAYOUT_WIRE_TRANSFER = 'wire_transfer';

    protected $fillable = [
        'store_id',
        'customer_id',
        'type',
        'amount',
        'balance_after',
        'source',
        'reference_type',
        'reference_id',
        'payout_method',
        'description',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function isCredit(): bool
    {
        return $this->type === self::TYPE_CREDIT;
    }

    public function isDebit(): bool
    {
        return $this->type === self::TYPE_DEBIT;
    }
}
