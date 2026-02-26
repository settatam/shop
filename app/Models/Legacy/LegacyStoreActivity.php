<?php

namespace App\Models\Legacy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class LegacyStoreActivity extends Model
{
    protected $connection = 'legacy';

    protected $table = 'store_activities';

    protected $fillable = [
        'user_id',
        'activity',
        'activityable_id',
        'activityable_type',
        'creatable_id',
        'creatable_type',
        'description',
    ];

    public function activityable(): MorphTo
    {
        return $this->morphTo();
    }
}
