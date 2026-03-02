<?php

namespace App\Enums;

enum StatusableType: string
{
    case Transaction = 'transaction';
    case Order = 'order';
    case Repair = 'repair';
    case Memo = 'memo';
    case Lead = 'lead';

    public function label(): string
    {
        return match ($this) {
            self::Transaction => 'Transaction',
            self::Order => 'Order',
            self::Repair => 'Repair',
            self::Memo => 'Memo',
            self::Lead => 'Lead',
        };
    }

    public function pluralLabel(): string
    {
        return match ($this) {
            self::Transaction => 'Transactions',
            self::Order => 'Orders',
            self::Repair => 'Repairs',
            self::Memo => 'Memos',
            self::Lead => 'Leads',
        };
    }

    public function modelClass(): string
    {
        return match ($this) {
            self::Transaction => \App\Models\Transaction::class,
            self::Order => \App\Models\Order::class,
            self::Repair => \App\Models\Repair::class,
            self::Memo => \App\Models\Memo::class,
            self::Lead => \App\Models\Lead::class,
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
            \App\Models\Lead::class, 'Lead' => self::Lead,
            default => null,
        };
    }
}
