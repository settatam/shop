<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVideo extends Model
{
    use HasFactory;

    public const TYPE_EXTERNAL = 'external';

    public const TYPE_UPLOADED = 'uploaded';

    public const PROVIDER_YOUTUBE = 'youtube';

    public const PROVIDER_VIMEO = 'vimeo';

    protected $fillable = [
        'product_id',
        'url',
        'title',
        'type',
        'provider',
        'thumbnail_path',
        'sort_order',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the embedded URL for the video.
     */
    public function getEmbedUrl(): ?string
    {
        if ($this->provider === self::PROVIDER_YOUTUBE) {
            // Extract YouTube video ID
            if (preg_match('/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $this->url, $matches)) {
                return 'https://www.youtube.com/embed/'.$matches[1];
            }
        }

        if ($this->provider === self::PROVIDER_VIMEO) {
            // Extract Vimeo video ID
            if (preg_match('/vimeo\.com\/(\d+)/', $this->url, $matches)) {
                return 'https://player.vimeo.com/video/'.$matches[1];
            }
        }

        return $this->url;
    }

    /**
     * Detect the video provider from URL.
     */
    public static function detectProvider(string $url): ?string
    {
        if (str_contains($url, 'youtube.com') || str_contains($url, 'youtu.be')) {
            return self::PROVIDER_YOUTUBE;
        }

        if (str_contains($url, 'vimeo.com')) {
            return self::PROVIDER_VIMEO;
        }

        return null;
    }
}
