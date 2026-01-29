<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PurchaseOrder extends Model
{
    use BelongsToStore, HasFactory, LogsActivity, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_APPROVED,
        self::STATUS_PARTIAL,
        self::STATUS_RECEIVED,
        self::STATUS_CLOSED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'store_id',
        'vendor_id',
        'warehouse_id',
        'created_by',
        'approved_by',
        'po_number',
        'status',
        'subtotal',
        'tax_amount',
        'shipping_cost',
        'discount_amount',
        'total',
        'order_date',
        'expected_date',
        'approved_at',
        'submitted_at',
        'closed_at',
        'cancelled_at',
        'shipping_method',
        'tracking_number',
        'vendor_notes',
        'internal_notes',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'order_date' => 'date',
            'expected_date' => 'date',
            'approved_at' => 'datetime',
            'submitted_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PurchaseOrder $purchaseOrder) {
            if (empty($purchaseOrder->po_number)) {
                $purchaseOrder->po_number = static::generatePoNumber($purchaseOrder->store_id);
            }
        });
    }

    public static function generatePoNumber(int $storeId): string
    {
        $prefix = 'PO';
        $date = now()->format('Ymd');
        $random = strtoupper(Str::random(4));

        return "{$prefix}-{$date}-{$random}";
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseOrderReceipt::class);
    }

    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeSubmitted($query)
    {
        return $query->where('status', self::STATUS_SUBMITTED);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeReceivable($query)
    {
        return $query->whereIn('status', [self::STATUS_APPROVED, self::STATUS_PARTIAL]);
    }

    public function scopeOpen($query)
    {
        return $query->whereNotIn('status', [self::STATUS_CLOSED, self::STATUS_CANCELLED]);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isReceivable(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_PARTIAL]);
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function submit(): void
    {
        if (! $this->isDraft()) {
            throw new \RuntimeException('Only draft purchase orders can be submitted.');
        }

        $this->update([
            'status' => self::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);
    }

    public function approve(User $approver): void
    {
        if (! $this->isSubmitted()) {
            throw new \RuntimeException('Only submitted purchase orders can be approved.');
        }

        $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);

        // Add incoming quantity to inventory records
        foreach ($this->items as $item) {
            $inventory = Inventory::firstOrCreate(
                [
                    'store_id' => $this->store_id,
                    'product_variant_id' => $item->product_variant_id,
                    'warehouse_id' => $this->warehouse_id,
                ],
                [
                    'quantity' => 0,
                    'reserved_quantity' => 0,
                    'incoming_quantity' => 0,
                ]
            );

            $inventory->increment('incoming_quantity', $item->quantity_ordered);
        }
    }

    public function cancel(): void
    {
        if (in_array($this->status, [self::STATUS_RECEIVED, self::STATUS_CLOSED, self::STATUS_CANCELLED])) {
            throw new \RuntimeException('This purchase order cannot be cancelled.');
        }

        // Remove incoming quantity if was approved
        if (in_array($this->status, [self::STATUS_APPROVED, self::STATUS_PARTIAL])) {
            foreach ($this->items as $item) {
                $remainingQty = $item->quantity_ordered - $item->quantity_received;
                if ($remainingQty > 0) {
                    $inventory = Inventory::where('store_id', $this->store_id)
                        ->where('product_variant_id', $item->product_variant_id)
                        ->where('warehouse_id', $this->warehouse_id)
                        ->first();

                    if ($inventory) {
                        $inventory->decrement('incoming_quantity', $remainingQty);
                    }
                }
            }
        }

        $this->update([
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
    }

    public function close(): void
    {
        if (! in_array($this->status, [self::STATUS_PARTIAL, self::STATUS_RECEIVED])) {
            throw new \RuntimeException('Only partially or fully received purchase orders can be closed.');
        }

        // Remove any remaining incoming quantity
        foreach ($this->items as $item) {
            $remainingQty = $item->quantity_ordered - $item->quantity_received;
            if ($remainingQty > 0) {
                $inventory = Inventory::where('store_id', $this->store_id)
                    ->where('product_variant_id', $item->product_variant_id)
                    ->where('warehouse_id', $this->warehouse_id)
                    ->first();

                if ($inventory) {
                    $inventory->decrement('incoming_quantity', $remainingQty);
                }
            }
        }

        $this->update([
            'status' => self::STATUS_CLOSED,
            'closed_at' => now(),
        ]);
    }

    public function recalculateTotals(): void
    {
        $this->loadMissing('items');

        $subtotal = $this->items->sum('line_total');

        $this->update([
            'subtotal' => $subtotal,
            'total' => $subtotal + $this->tax_amount + $this->shipping_cost - $this->discount_amount,
        ]);
    }

    public function updateReceivingStatus(): void
    {
        $this->loadMissing('items');

        $totalOrdered = $this->items->sum('quantity_ordered');
        $totalReceived = $this->items->sum('quantity_received');

        if ($totalReceived === 0) {
            return;
        }

        if ($totalReceived >= $totalOrdered) {
            $this->update(['status' => self::STATUS_RECEIVED]);
        } elseif ($totalReceived > 0) {
            $this->update(['status' => self::STATUS_PARTIAL]);
        }
    }

    public function getTotalOrderedQuantityAttribute(): int
    {
        return $this->items->sum('quantity_ordered');
    }

    public function getTotalReceivedQuantityAttribute(): int
    {
        return $this->items->sum('quantity_received');
    }

    public function getReceivingProgressAttribute(): float
    {
        $ordered = $this->total_ordered_quantity;

        if ($ordered === 0) {
            return 0;
        }

        return round(($this->total_received_quantity / $ordered) * 100, 2);
    }

    protected function getActivityPrefix(): string
    {
        return 'purchase_orders';
    }

    protected function getLoggableAttributes(): array
    {
        return ['id', 'po_number', 'status', 'total', 'vendor_id'];
    }

    protected function getActivityIdentifier(): string
    {
        return $this->po_number ?? "#{$this->id}";
    }
}
