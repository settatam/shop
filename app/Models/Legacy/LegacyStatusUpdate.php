<?php

namespace App\Models\Legacy;

use Illuminate\Database\Eloquent\Model;

class LegacyStatusUpdate extends Model
{
    protected $connection = 'legacy';

    protected $table = 'status_updates';

    protected $fillable = [
        'store_id',
        'user_id',
        'updateable_id',
        'updateable_type',
        'previous_status',
        'current_status',
    ];
}
