<?php

namespace App\Services\Video;

use App\Models\Product;
use App\Models\ProductVideo;
use App\Models\Store;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideoService
{
    protected string $disk;

    public function __construct()
    {
        $this->disk = config('filesystems.disks.do_spaces.bucket')
            ? 'do_spaces'
            : 'public';
    }

    /**
     * Upload a video file and create a ProductVideo record.
     */
    public function upload(
        UploadedFile $file,
        Product $product,
        Store $store,
        ?string $title = null,
        int $sortOrder = 0
    ): ProductVideo {
        $storeSlug = Str::slug($store->name);
        $filename = $this->generateFilename($file);
        $videoPath = "{$storeSlug}/videos/{$product->id}/{$filename}";

        // Upload video with public visibility
        Storage::disk($this->disk)->put($videoPath, file_get_contents($file->path()), [
            'visibility' => 'public',
            'ACL' => 'public-read',
        ]);

        $url = $this->getFullUrl($videoPath);

        return ProductVideo::create([
            'product_id' => $product->id,
            'url' => $url,
            'title' => $title ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'type' => ProductVideo::TYPE_UPLOADED,
            'provider' => null,
            'thumbnail_path' => null,
            'sort_order' => $sortOrder,
        ]);
    }

    /**
     * Create a ProductVideo record for an external URL.
     */
    public function createFromUrl(
        Product $product,
        string $url,
        ?string $title = null,
        int $sortOrder = 0
    ): ProductVideo {
        $provider = ProductVideo::detectProvider($url);

        return ProductVideo::create([
            'product_id' => $product->id,
            'url' => $url,
            'title' => $title,
            'type' => ProductVideo::TYPE_EXTERNAL,
            'provider' => $provider,
            'thumbnail_path' => null,
            'sort_order' => $sortOrder,
        ]);
    }

    /**
     * Upload multiple video files.
     *
     * @param  array<UploadedFile>  $files
     * @param  array<string>  $titles
     * @return array<ProductVideo>
     */
    public function uploadMultiple(
        array $files,
        Product $product,
        Store $store,
        array $titles = [],
        int $startSortOrder = 0
    ): array {
        $videos = [];
        $sortOrder = $startSortOrder;

        foreach ($files as $index => $file) {
            $title = $titles[$index] ?? null;

            $videos[] = $this->upload(
                file: $file,
                product: $product,
                store: $store,
                title: $title,
                sortOrder: $sortOrder++
            );
        }

        return $videos;
    }

    /**
     * Delete a video from storage and database.
     */
    public function delete(ProductVideo $video): bool
    {
        // Only delete from storage if it was an uploaded video
        if ($video->type === ProductVideo::TYPE_UPLOADED) {
            $path = $this->extractPathFromUrl($video->url);
            if ($path) {
                Storage::disk($this->disk)->delete($path);
            }
        }

        return $video->delete();
    }

    /**
     * Generate a unique filename for the upload.
     */
    protected function generateFilename(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension() ?: 'mp4';
        $basename = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));

        return $basename.'-'.Str::random(8).'.'.$extension;
    }

    /**
     * Get the full URL for a path.
     */
    protected function getFullUrl(string $path): string
    {
        if ($this->disk === 'do_spaces') {
            $cdnUrl = rtrim(config('filesystems.disks.do_spaces.url'), '/');

            return "{$cdnUrl}/{$path}";
        }

        return Storage::disk($this->disk)->url($path);
    }

    /**
     * Extract the storage path from a full URL.
     */
    protected function extractPathFromUrl(string $url): ?string
    {
        if ($this->disk === 'do_spaces') {
            $cdnUrl = rtrim(config('filesystems.disks.do_spaces.url'), '/');

            if (str_starts_with($url, $cdnUrl)) {
                return substr($url, strlen($cdnUrl) + 1);
            }
        }

        return null;
    }
}
