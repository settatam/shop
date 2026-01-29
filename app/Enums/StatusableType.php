<?php

namespace App\Enums;

enum StatusableType: string
{
    case Transaction = 'transaction';
    case Order = 'order';
    case Repair = 'repair';
    case Memo = 'memo';

    public function label(): string
    {
        return match ($this) {
            self::Transaction => 'Transaction',
            self::Order => 'Order',
            self::Repair => 'Repair',
            self::Memo => 'Memo',
        };
    }

    public function pluralLabel(): string
    {
        return match ($this) {
            self::Transaction => 'Transactions',
            self::Order => 'Orders',
            self::Repair => 'Repairs',
            self::Memo => 'Memos',
        };
    }

    public function modelClass(): string
    {
        return match ($this) {
            self::Transaction => \App\Models\Transaction::class,
            self::Order => \App\Models\Order::class,
            self::Repair => \App\Models\Repair::class,
            self::Memo => \App\Models\Memo::class,
        };
    }

    /**
     * Get the entity type from a model class name.
     */
    public static function fromModel(string $modelClass): ?self
    {
        return match ($modelClass) {
            \App\Models\Transaction::class, 'Transaction' => self::Transaction,
            \App\Models\Order::class, 'Order' => self::Order,
            \App\Models\Repair::class, 'Repair' => self::Repair,
            \App\Models\Memo::class, 'Memo' => self::Memo,
            default => null,
        };
    }
}
