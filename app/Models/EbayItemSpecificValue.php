<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EbayItemSpecificValue extends Model
{
    protected $fillable = [
        'ebay_category_id',
        'ebay_item_specific_id',
        'value',
    ];

    public function itemSpecific(): BelongsTo
    {
        return $this->belongsTo(EbayItemSpecific::class, 'ebay_item_specific_id');
    }
}
