<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class OrderItem extends Model
{
    use HasFactory, Searchable, SoftDeletes;

    protected $fillable = [
        'order_id',
        'product_id',
        'product_variant_id',
        'bucket_item_id',
        'sku',
        'title',
        'quantity',
        'price',
        'cost',
        'wholesale_value',
        'discount',
        'tax',
        'shipstation_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
            'wholesale_value' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function bucketItem(): BelongsTo
    {
        return $this->belongsTo(BucketItem::class);
    }

    public function getLineTotalAttribute(): float
    {
        $subtotal = ((float) $this->price - ((float) $this->discount ?? 0)) * $this->quantity;

        return $subtotal + ((float) $this->tax ?? 0);
    }

    public function getLineProfitAttribute(): float
    {
        if ($this->cost === null) {
            return 0;
        }

        return ($this->price - $this->discount - $this->cost) * $this->quantity;
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $this->loadMissing('order', 'product.brand', 'product.category');

        return [
            'id' => $this->id,
            'title' => $this->title,
            'sku' => $this->sku,
            'brand' => $this->product?->brand?->name,
            'category' => $this->product?->category?->name,
            'store_id' => $this->order?->store_id,
            'order_id' => $this->order_id,
            'product_id' => $this->product_id,
            'created_at' => $this->created_at?->timestamp,
        ];
    }

    /**
     * Determine if the model should be searchable.
     */
    public function shouldBeSearchable(): bool
    {
        return ! $this->trashed();
    }
}
