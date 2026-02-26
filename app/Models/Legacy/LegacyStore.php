<?php

namespace App\Models\Legacy;

use Illuminate\Database\Eloquent\Model;

class LegacyStore extends Model
{
    protected $connection = 'legacy';

    protected $table = 'stores';

    protected $guarded = [];

    public static function findByName(string $name): ?self
    {
        return static::where('name', $name)->first();
    }
}
