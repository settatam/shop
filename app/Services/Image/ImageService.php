<?php

namespace App\Services\Image;

use App\Models\Image;
use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class ImageService
{
    protected string $disk;

    protected int $thumbnailWidth = 300;

    protected int $thumbnailHeight = 300;

    public function __construct()
    {
        // Use DO Spaces if configured, otherwise fall back to public disk
        $this->disk = config('filesystems.disks.do_spaces.bucket')
            ? 'do_spaces'
            : 'public';
    }

    /**
     * Upload an image for any imageable model.
     *
     * @return array{url: string, thumbnail_url: string, path: string, size: int, mime_type: string, width: int, height: int}
     */
    public function upload(
        UploadedFile $file,
        Model $imageable,
        Store $store,
        string $folder = 'uploads'
    ): array {
        $storeSlug = Str::slug($store->name);
        $filename = $this->generateFilename($file);

        // Store paths organized by store slug and folder
        $imagePath = "{$storeSlug}/{$folder}/{$imageable->id}/{$filename}";
        $thumbnailPath = "{$storeSlug}/{$folder}/{$imageable->id}/thumbnails/{$filename}";

        // Get image dimensions before upload
        [$width, $height] = getimagesize($file->path()) ?: [null, null];

        // Upload original image with public visibility and ACL
        Storage::disk($this->disk)->put($imagePath, file_get_contents($file->path()), [
            'visibility' => 'public',
            'ACL' => 'public-read',
        ]);

        // Generate and upload thumbnail with public visibility and ACL
        $thumbnail = $this->generateThumbnail($file);
        Storage::disk($this->disk)->put($thumbnailPath, $thumbnail, [
            'visibility' => 'public',
            'ACL' => 'public-read',
        ]);

        // Get the full CDN URLs
        $url = $this->getFullUrl($imagePath);
        $thumbnailUrl = $this->getFullUrl($thumbnailPath);

        return [
            'url' => $url,
            'thumbnail_url' => $thumbnailUrl,
            'path' => $imagePath,
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Create an Image record with uploaded image data.
     */
    public function create(
        UploadedFile $file,
        Model $imageable,
        Store $store,
        string $folder = 'uploads',
        ?string $altText = null,
        int $sortOrder = 0,
        bool $isPrimary = false
    ): Image {
        $uploadData = $this->upload($file, $imageable, $store, $folder);

        return Image::create([
            'store_id' => $store->id,
            'imageable_type' => get_class($imageable),
            'imageable_id' => $imageable->id,
            'path' => $uploadData['path'],
            'url' => $uploadData['url'],
            'thumbnail_url' => $uploadData['thumbnail_url'],
            'alt_text' => $altText,
            'disk' => $this->disk,
            'size' => $uploadData['size'],
            'mime_type' => $uploadData['mime_type'],
            'width' => $uploadData['width'],
            'height' => $uploadData['height'],
            'sort_order' => $sortOrder,
            'is_primary' => $isPrimary,
        ]);
    }

    /**
     * Delete an image from storage and database.
     */
    public function delete(Image $image): bool
    {
        $disk = $image->disk ?? $this->disk;

        // Delete the original image
        if ($image->path) {
            Storage::disk($disk)->delete($image->path);

            // Also delete thumbnail
            $thumbnailPath = $this->getThumbnailPath($image->path);
            Storage::disk($disk)->delete($thumbnailPath);
        }

        return $image->delete();
    }

    /**
     * Upload multiple images for an imageable model.
     *
     * @param  array<UploadedFile>  $files
     * @return array<Image>
     */
    public function uploadMultiple(
        array $files,
        Model $imageable,
        Store $store,
        string $folder = 'uploads',
        ?string $altText = null,
        int $startSortOrder = 0,
        bool $setFirstAsPrimary = false
    ): array {
        $images = [];
        $sortOrder = $startSortOrder;

        foreach ($files as $index => $file) {
            $isPrimary = $setFirstAsPrimary && $index === 0;

            $images[] = $this->create(
                file: $file,
                imageable: $imageable,
                store: $store,
                folder: $folder,
                altText: $altText,
                sortOrder: $sortOrder++,
                isPrimary: $isPrimary
            );
        }

        return $images;
    }

    /**
     * Generate a thumbnail from an uploaded file.
     */
    protected function generateThumbnail(UploadedFile $file): string
    {
        $manager = new ImageManager(new Driver);
        $image = $manager->read($file->path());

        $image->cover($this->thumbnailWidth, $this->thumbnailHeight);

        return $image->toJpeg(85)->toString();
    }

    /**
     * Generate a unique filename for the upload.
     */
    protected function generateFilename(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension() ?: 'jpg';
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

        // For local/public disk, use Storage URL
        return Storage::disk($this->disk)->url($path);
    }

    /**
     * Get the thumbnail path from the original path.
     */
    protected function getThumbnailPath(string $originalPath): string
    {
        $directory = dirname($originalPath);
        $filename = basename($originalPath);

        return "{$directory}/thumbnails/{$filename}";
    }

    /**
     * Set the disk to use for uploads.
     */
    public function setDisk(string $disk): self
    {
        $this->disk = $disk;

        return $this;
    }

    /**
     * Set thumbnail dimensions.
     */
    public function setThumbnailSize(int $width, int $height): self
    {
        $this->thumbnailWidth = $width;
        $this->thumbnailHeight = $height;

        return $this;
    }
}
