<?php

namespace App\Models;

use App\Enums\ConversationChannel;
use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChannelConfiguration extends Model
{
    use BelongsToStore, HasFactory;

    protected $fillable = [
        'store_id',
        'channel',
        'credentials',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'channel' => ConversationChannel::class,
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
        ];
    }
}
