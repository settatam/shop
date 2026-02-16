<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use App\Traits\HasAddresses;
use App\Traits\HasNotes;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vendor extends Model
{
    use BelongsToStore, HasAddresses, HasFactory, HasNotes, LogsActivity, SoftDeletes;

    public const PAYMENT_TERMS_NET_15 = 'net_15';

    public const PAYMENT_TERMS_NET_30 = 'net_30';

    public const PAYMENT_TERMS_NET_45 = 'net_45';

    public const PAYMENT_TERMS_NET_60 = 'net_60';

    public const PAYMENT_TERMS_DUE_ON_RECEIPT = 'due_on_receipt';

    public const PAYMENT_TERMS_PREPAID = 'prepaid';

    public const PAYMENT_TERMS = [
        self::PAYMENT_TERMS_NET_15,
        self::PAYMENT_TERMS_NET_30,
        self::PAYMENT_TERMS_NET_45,
        self::PAYMENT_TERMS_NET_60,
        self::PAYMENT_TERMS_DUE_ON_RECEIPT,
        self::PAYMENT_TERMS_PREPAID,
    ];

    protected $fillable = [
        'store_id',
        'name',
        'code',
        'company_name',
        'email',
        'phone',
        'website',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'postal_code',
        'country',
        'tax_id',
        'payment_terms',
        'lead_time_days',
        'currency_code',
        'contact_name',
        'contact_email',
        'contact_phone',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'lead_time_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function productVariants(): BelongsToMany
    {
        return $this->belongsToMany(ProductVariant::class, 'product_vendor')
            ->withPivot(['vendor_sku', 'cost', 'lead_time_days', 'minimum_order_qty', 'is_preferred', 'notes'])
            ->withTimestamps();
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function memos(): HasMany
    {
        return $this->hasMany(Memo::class);
    }

    public function repairs(): HasMany
    {
        return $this->hasMany(Repair::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch($query, ?string $search)
    {
        if (! $search) {
            return $query;
        }

        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhere('company_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%");
        });
    }

    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address_line1,
            $this->address_line2,
            $this->city,
            $this->state,
            $this->postal_code,
            $this->country,
        ]);

        return implode(', ', $parts);
    }

    public function getDisplayNameAttribute(): string
    {
        if ($this->company_name) {
            return "{$this->name} ({$this->company_name})";
        }

        return $this->name;
    }

    protected function getActivityPrefix(): string
    {
        return 'vendors';
    }

    protected function getLoggableAttributes(): array
    {
        return ['id', 'name', 'code', 'company_name', 'is_active'];
    }

    protected function getActivityIdentifier(): string
    {
        return $this->name ?? "#{$this->id}";
    }
}
