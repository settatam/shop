<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReturnPolicy extends Model
{
    use BelongsToStore, HasFactory;

    protected $fillable = [
        'store_id',
        'name',
        'description',
        'return_window_days',
        'allow_refund',
        'allow_store_credit',
        'allow_exchange',
        'restocking_fee_percent',
        'require_receipt',
        'require_original_packaging',
        'excluded_conditions',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'return_window_days' => 'integer',
            'allow_refund' => 'boolean',
            'allow_store_credit' => 'boolean',
            'allow_exchange' => 'boolean',
            'restocking_fee_percent' => 'decimal:2',
            'require_receipt' => 'boolean',
            'require_original_packaging' => 'boolean',
            'excluded_conditions' => 'array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(ProductReturn::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function isEligibleForReturn(Order $order): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $orderDate = $order->date_of_purchase ?? $order->created_at;
        $daysSinceOrder = $orderDate->diffInDays(now());

        return $daysSinceOrder <= $this->return_window_days;
    }

    public function calculateRestockingFee(float $amount): float
    {
        if ($this->restocking_fee_percent <= 0) {
            return 0;
        }

        return round($amount * ($this->restocking_fee_percent / 100), 2);
    }

    public function setAsDefault(): self
    {
        static::where('store_id', $this->store_id)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        $this->update(['is_default' => true]);

        return $this;
    }
}
