<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyMetafieldDefinition extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_marketplace_id',
        'key',
        'namespace',
        'name',
        'type',
        'description',
        'shopify_gid',
    ];

    public function marketplace(): BelongsTo
    {
        return $this->belongsTo(StoreMarketplace::class, 'store_marketplace_id');
    }
}
