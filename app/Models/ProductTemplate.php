<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class ProductTemplate extends Model
{
    use BelongsToStore, HasFactory, LogsActivity, Searchable, SoftDeletes;

    protected $fillable = [
        'store_id',
        'name',
        'description',
        'is_active',
        'ai_generated',
        'generation_prompt',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'ai_generated' => 'boolean',
        ];
    }

    public function fields(): HasMany
    {
        return $this->hasMany(ProductTemplateField::class)->orderBy('sort_order');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class, 'template_id');
    }

    public function platformMappings(): HasMany
    {
        return $this->hasMany(TemplatePlatformMapping::class);
    }

    /**
     * Get the platform mapping for a specific platform.
     */
    public function getMappingForPlatform(string $platform): ?TemplatePlatformMapping
    {
        return $this->platformMappings->firstWhere('platform', $platform);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    protected function getActivityPrefix(): string
    {
        return 'templates';
    }

    protected function getLoggableAttributes(): array
    {
        return ['id', 'name', 'description', 'is_active'];
    }

    protected function getActivityIdentifier(): string
    {
        return $this->name ?? "#{$this->id}";
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'store_id' => $this->store_id,
            'is_active' => $this->is_active,
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
