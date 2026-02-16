<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class VoiceMemory extends Model
{
    /** @use HasFactory<\Database\Factories\VoiceMemoryFactory> */
    use BelongsToStore, HasFactory;

    protected $fillable = [
        'store_id',
        'memory_type',
        'category',
        'content',
        'confidence',
        'source',
        'source_id',
        'expires_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:2',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('memory_type', $type);
    }

    public function scopeInCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    public function scopeHighConfidence(Builder $query, float $threshold = 0.7): Builder
    {
        return $query->where('confidence', '>=', $threshold);
    }

    public function scopeSearch(Builder $query, string $searchTerm): Builder
    {
        // Use fulltext search for MySQL, fallback to LIKE for other drivers
        if ($query->getConnection()->getDriverName() === 'mysql') {
            return $query->whereFullText('content', $searchTerm);
        }

        return $query->where('content', 'like', '%'.trim($searchTerm).'%');
    }

    public static function remember(
        int $storeId,
        string $content,
        string $type = 'fact',
        ?string $category = null,
        float $confidence = 1.0,
        ?string $source = 'voice_conversation',
        ?string $sourceId = null
    ): self {
        return static::create([
            'store_id' => $storeId,
            'memory_type' => $type,
            'category' => $category,
            'content' => $content,
            'confidence' => $confidence,
            'source' => $source,
            'source_id' => $sourceId,
        ]);
    }

    public static function recall(int $storeId, ?string $category = null, int $limit = 10): Collection
    {
        $query = static::forStore($storeId)->active();

        if ($category) {
            $query->inCategory($category);
        }

        return $query->orderByDesc('confidence')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public static function findRelevant(int $storeId, string $query, int $limit = 5): Collection
    {
        return static::forStore($storeId)
            ->active()
            ->search($query)
            ->orderByDesc('confidence')
            ->limit($limit)
            ->get();
    }

    public function deactivate(): self
    {
        $this->update(['is_active' => false]);

        return $this;
    }

    public function refreshConfidence(float $newConfidence): self
    {
        $this->update(['confidence' => $newConfidence]);

        return $this;
    }
}
