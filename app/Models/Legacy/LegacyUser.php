<?php

namespace App\Models\Legacy;

use Illuminate\Database\Eloquent\Model;

class LegacyUser extends Model
{
    protected $connection = 'legacy';

    protected $table = 'users';

    protected $guarded = [];
}
