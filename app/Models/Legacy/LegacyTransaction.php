<?php

namespace App\Models\Legacy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LegacyTransaction extends Model
{
    use SoftDeletes;

    protected $connection = 'legacy';

    protected $table = 'transactions';

    protected $morphClass = 'App\Models\Transaction';

    protected $guarded = [];

    public function items(): HasMany
    {
        return $this->hasMany(LegacyTransactionItem::class, 'transaction_id');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(LegacyStoreActivity::class, 'activityable');
    }

    /**
     * Recalculate final_offer from the sum of item buy_prices.
     */
    public function calculateOfferFromItems(): void
    {
        $this->final_offer = round((float) $this->items()->sum('buy_price'), 2);
        $this->save();
    }

    public function getMorphClass(): string
    {
        return $this->morphClass;
    }
}
