<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BugReport extends Model
{
    protected $fillable = [
        'store_id',
        'user_id',
        'description',
        'url',
        'user_agent',
        'screenshot_path',
        'status',
        'resolution_notes',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
