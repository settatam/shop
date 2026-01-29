<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Platform extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'logo_url',
        'description',
        'api_base_url',
        'is_active',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function categories(): HasMany
    {
        return $this->hasMany(PlatformCategory::class);
    }

    public function fieldMappings(): HasMany
    {
        return $this->hasMany(PlatformFieldMapping::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
