<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Note extends Model
{
    use BelongsToStore, HasFactory;

    protected $fillable = [
        'store_id',
        'user_id',
        'notable_type',
        'notable_id',
        'content',
    ];

    /**
     * Get the notable model (Transaction, Order, Repair, etc.).
     */
    public function notable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the user who created the note.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
