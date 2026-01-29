<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class CustomerDocument extends Model
{
    /** @use HasFactory<\Database\Factories\CustomerDocumentFactory> */
    use HasFactory;

    public const TYPE_ID_FRONT = 'id_front';

    public const TYPE_ID_BACK = 'id_back';

    public const TYPE_OTHER = 'other';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'customer_id',
        'type',
        'path',
        'original_filename',
        'mime_type',
        'size',
        'notes',
        'uploaded_by',
    ];

    /**
     * @var array<string, string>
     */
    protected $appends = ['url'];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Get the document URL.
     */
    public function getUrlAttribute(): string
    {
        if (str_starts_with($this->path, 'http')) {
            return $this->path;
        }

        // Try s3 disk first (DO Spaces), fallback to public disk
        $disk = config('filesystems.default') === 's3' ? 's3' : 'public';

        return Storage::disk($disk)->url($this->path);
    }

    /**
     * Get a human-readable type label.
     */
    public function getTypeLabelAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_ID_FRONT => 'ID Front',
            self::TYPE_ID_BACK => 'ID Back',
            self::TYPE_OTHER => 'Other Document',
            default => ucfirst($this->type),
        };
    }

    /**
     * Check if this is an image.
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type ?? '', 'image/');
    }

    /**
     * Check if this is a PDF.
     */
    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    /**
     * Get file size in human readable format.
     */
    public function getHumanSizeAttribute(): string
    {
        $bytes = $this->size ?? 0;

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2).' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2).' KB';
        }

        return $bytes.' bytes';
    }

    /**
     * Delete the document file from storage.
     */
    public function deleteFile(): bool
    {
        $disk = config('filesystems.default') === 's3' ? 's3' : 'public';

        return Storage::disk($disk)->delete($this->path);
    }

    protected static function booted(): void
    {
        static::deleting(function (CustomerDocument $document) {
            $document->deleteFile();
        });
    }
}
