<?php

namespace App\Models\Legacy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class LegacyTransactionItem extends Model
{
    protected $connection = 'legacy';

    protected $table = 'transaction_items';

    protected $morphClass = 'App\Models\TransactionItem';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'buy_price' => 'decimal:2',
            'dwt' => 'decimal:4',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(LegacyTransaction::class, 'transaction_id');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(LegacyStoreActivity::class, 'activityable');
    }

    public function getMorphClass(): string
    {
        return $this->morphClass;
    }
}
