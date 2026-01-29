<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TransactionPayout extends Model
{
    use BelongsToStore, HasFactory, SoftDeletes;

    public const PROVIDER_PAYPAL = 'paypal';

    public const RECIPIENT_TYPE_EMAIL = 'EMAIL';

    public const RECIPIENT_TYPE_PHONE = 'PHONE';

    public const RECIPIENT_TYPE_PAYPAL_ID = 'PAYPAL_ID';

    public const WALLET_PAYPAL = 'PAYPAL';

    public const WALLET_VENMO = 'VENMO';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SUCCESS = 'SUCCESS';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_UNCLAIMED = 'UNCLAIMED';

    public const STATUS_RETURNED = 'RETURNED';

    public const STATUS_ONHOLD = 'ONHOLD';

    public const STATUS_BLOCKED = 'BLOCKED';

    public const STATUS_REFUNDED = 'REFUNDED';

    protected $fillable = [
        'store_id',
        'transaction_id',
        'user_id',
        'provider',
        'payout_batch_id',
        'payout_item_id',
        'transaction_id_external',
        'recipient_type',
        'recipient_value',
        'recipient_wallet',
        'amount',
        'currency',
        'status',
        'error_code',
        'error_message',
        'api_response',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'api_response' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isVenmo(): bool
    {
        return $this->recipient_wallet === self::WALLET_VENMO;
    }

    public function isPayPal(): bool
    {
        return $this->recipient_wallet === self::WALLET_PAYPAL || $this->recipient_wallet === null;
    }

    public function getTrackingUrl(): ?string
    {
        if (! $this->payout_item_id || ! $this->payout_batch_id) {
            return null;
        }

        return 'https://www.paypal.com/activity/payment/'.$this->payout_item_id;
    }

    public function markAsProcessing(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
        ]);
    }

    public function markAsSuccess(string $transactionId, array $response = []): void
    {
        $this->update([
            'status' => self::STATUS_SUCCESS,
            'transaction_id_external' => $transactionId,
            'api_response' => $response,
            'processed_at' => now(),
        ]);
    }

    public function markAsFailed(string $errorCode, string $errorMessage, array $response = []): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'api_response' => $response,
            'processed_at' => now(),
        ]);
    }

    public function updateFromPayPalStatus(array $item): void
    {
        $this->update([
            'status' => $item['transaction_status'] ?? $this->status,
            'payout_item_id' => $item['payout_item_id'] ?? $this->payout_item_id,
            'transaction_id_external' => $item['transaction_id'] ?? $this->transaction_id_external,
            'error_code' => $item['errors']['name'] ?? null,
            'error_message' => $item['errors']['message'] ?? null,
            'api_response' => $item,
            'processed_at' => now(),
        ]);
    }
}
