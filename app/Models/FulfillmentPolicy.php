<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FulfillmentPolicy extends Model
{
    use BelongsToStore, HasFactory;

    protected $fillable = [
        'store_id',
        'name',
        'description',
        'handling_time_value',
        'handling_time_unit',
        'shipping_type',
        'domestic_shipping_cost',
        'international_shipping_cost',
        'free_shipping',
        'shipping_carrier',
        'shipping_service',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'handling_time_value' => 'integer',
            'domestic_shipping_cost' => 'decimal:2',
            'international_shipping_cost' => 'decimal:2',
            'free_shipping' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
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
