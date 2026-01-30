<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class State extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'abbreviation',
        'country_code',
    ];

    /**
     * Get addresses in this state.
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class, 'state_id');
    }

    /**
     * Get all states for a country.
     *
     * @return Collection<int, State>
     */
    public static function forCountry(string $countryCode = 'US'): Collection
    {
        return static::where('country_code', $countryCode)
            ->where('abbreviation', '!=', 'XX')
            ->orderBy('name')
            ->get();
    }

    /**
     * Find state by abbreviation.
     */
    public static function findByAbbreviation(string $abbreviation, string $countryCode = 'US'): ?self
    {
        return static::where('abbreviation', $abbreviation)
            ->where('country_code', $countryCode)
            ->first();
    }
}
