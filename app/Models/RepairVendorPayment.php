<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class RepairVendorPayment extends Model
{
    use BelongsToStore, HasFactory, SoftDeletes;

    protected $fillable = [
        'store_id',
        'repair_id',
        'vendor_id',
        'user_id',
        'check_number',
        'amount',
        'vendor_invoice_amount',
        'reason',
        'payment_date',
        'attachment_path',
        'attachment_name',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'vendor_invoice_amount' => 'decimal:2',
            'payment_date' => 'date',
        ];
    }

    public function repair(): BelongsTo
    {
        return $this->belongsTo(Repair::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getAttachmentUrlAttribute(): ?string
    {
        if (! $this->attachment_path) {
            return null;
        }

        return Storage::disk('local')->url($this->attachment_path);
    }

    public function hasAttachment(): bool
    {
        return ! empty($this->attachment_path);
    }

    public function deleteAttachment(): void
    {
        if ($this->attachment_path && Storage::disk('local')->exists($this->attachment_path)) {
            Storage::disk('local')->delete($this->attachment_path);
        }

        $this->update([
            'attachment_path' => null,
            'attachment_name' => null,
        ]);
    }
}
