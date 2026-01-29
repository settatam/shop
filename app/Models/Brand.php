<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Brand extends Model
{
    use BelongsToStore, HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'store_id',
        'page_title',
        'description',
        'meta_title',
        'meta_description',
        'meta_keyword',
        'sort_order',
        'slug',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
