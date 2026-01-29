<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionOffer extends Model
{
    /** @use HasFactory<\Database\Factories\TransactionOfferFactory> */
    use HasFactory;

    // Status constants
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_SUPERSEDED = 'superseded';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'transaction_id',
        'user_id',
        'amount',
        'status',
        'admin_notes',
        'customer_response',
        'responded_by_user_id',
        'responded_by_customer_id',
        'responded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'responded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Transaction, TransactionOffer>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * The user who created/submitted the offer (admin).
     *
     * @return BelongsTo<User, TransactionOffer>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The admin user who responded to the offer (if admin responded).
     *
     * @return BelongsTo<User, TransactionOffer>
     */
    public function respondedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by_user_id');
    }

    /**
     * The customer who responded to the offer (if customer responded).
     *
     * @return BelongsTo<Customer, TransactionOffer>
     */
    public function respondedByCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'responded_by_customer_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public function isDeclined(): bool
    {
        return $this->status === self::STATUS_DECLINED;
    }

    public function isSuperseded(): bool
    {
        return $this->status === self::STATUS_SUPERSEDED;
    }

    /**
     * Accept the offer.
     *
     * @param  int|null  $userId  Admin user ID if accepted by admin
     * @param  int|null  $customerId  Customer ID if accepted by customer
     */
    public function accept(?int $userId = null, ?int $customerId = null): self
    {
        $this->update([
            'status' => self::STATUS_ACCEPTED,
            'responded_by_user_id' => $userId,
            'responded_by_customer_id' => $customerId,
            'responded_at' => now(),
        ]);

        return $this;
    }

    /**
     * Decline the offer.
     *
     * @param  string|null  $response  Customer's response/reason
     * @param  int|null  $userId  Admin user ID if declined by admin
     * @param  int|null  $customerId  Customer ID if declined by customer
     */
    public function decline(?string $response = null, ?int $userId = null, ?int $customerId = null): self
    {
        $this->update([
            'status' => self::STATUS_DECLINED,
            'customer_response' => $response,
            'responded_by_user_id' => $userId,
            'responded_by_customer_id' => $customerId,
            'responded_at' => now(),
        ]);

        return $this;
    }

    /**
     * Check if the offer was responded to by the customer.
     */
    public function wasRespondedByCustomer(): bool
    {
        return $this->responded_by_customer_id !== null;
    }

    /**
     * Check if the offer was responded to by an admin.
     */
    public function wasRespondedByAdmin(): bool
    {
        return $this->responded_by_user_id !== null;
    }

    /**
     * Get the name of the responder.
     */
    public function getResponderName(): ?string
    {
        if ($this->wasRespondedByCustomer()) {
            return $this->respondedByCustomer?->full_name ?? 'Customer';
        }

        if ($this->wasRespondedByAdmin()) {
            return $this->respondedByUser?->name ?? 'Admin';
        }

        return null;
    }

    public function supersede(): self
    {
        $this->update([
            'status' => self::STATUS_SUPERSEDED,
        ]);

        return $this;
    }

    /**
     * Get all available statuses.
     *
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_PENDING => 'Pending',
            self::STATUS_ACCEPTED => 'Accepted',
            self::STATUS_DECLINED => 'Declined',
            self::STATUS_SUPERSEDED => 'Superseded',
        ];
    }
}
