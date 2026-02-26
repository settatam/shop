<?php

namespace App\Services\Credits;

use App\Models\Customer;
use App\Models\StoreCredit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StoreCreditService
{
    /**
     * Issue store credit to a customer (adds to balance).
     */
    public function issue(
        Customer $customer,
        float $amount,
        string $source,
        ?Model $reference = null,
        ?string $description = null,
        ?int $userId = null,
        ?string $payoutMethod = null,
    ): StoreCredit {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Credit amount must be greater than zero.');
        }

        return DB::transaction(function () use ($customer, $amount, $source, $reference, $description, $userId, $payoutMethod) {
            $customer = Customer::lockForUpdate()->find($customer->id);

            $newBalance = (float) $customer->store_credit_balance + $amount;

            $entry = StoreCredit::create([
                'store_id' => $customer->store_id,
                'customer_id' => $customer->id,
                'type' => StoreCredit::TYPE_CREDIT,
                'amount' => $amount,
                'balance_after' => $newBalance,
                'source' => $source,
                'reference_type' => $reference ? get_class($reference) : null,
                'reference_id' => $reference?->id,
                'payout_method' => $payoutMethod,
                'description' => $description,
                'user_id' => $userId ?? auth()->id(),
            ]);

            $customer->update(['store_credit_balance' => $newBalance]);

            return $entry;
        });
    }

    /**
     * Redeem store credit from a customer (subtracts from balance).
     */
    public function redeem(
        Customer $customer,
        float $amount,
        string $source,
        ?Model $reference = null,
        ?string $description = null,
        ?int $userId = null,
    ): StoreCredit {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Redeem amount must be greater than zero.');
        }

        return DB::transaction(function () use ($customer, $amount, $source, $reference, $description, $userId) {
            $customer = Customer::lockForUpdate()->find($customer->id);

            if ($amount > (float) $customer->store_credit_balance) {
                throw new InvalidArgumentException('Insufficient store credit balance.');
            }

            $newBalance = (float) $customer->store_credit_balance - $amount;

            $entry = StoreCredit::create([
                'store_id' => $customer->store_id,
                'customer_id' => $customer->id,
                'type' => StoreCredit::TYPE_DEBIT,
                'amount' => $amount,
                'balance_after' => $newBalance,
                'source' => $source,
                'reference_type' => $reference ? get_class($reference) : null,
                'reference_id' => $reference?->id,
                'description' => $description,
                'user_id' => $userId ?? auth()->id(),
            ]);

            $customer->update(['store_credit_balance' => $newBalance]);

            return $entry;
        });
    }

    /**
     * Cash out store credit for a customer.
     */
    public function cashOut(
        Customer $customer,
        float $amount,
        string $payoutMethod,
        ?string $description = null,
        ?int $userId = null,
    ): StoreCredit {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Cash out amount must be greater than zero.');
        }

        return DB::transaction(function () use ($customer, $amount, $payoutMethod, $description, $userId) {
            $customer = Customer::lockForUpdate()->find($customer->id);

            if ($amount > (float) $customer->store_credit_balance) {
                throw new InvalidArgumentException('Insufficient store credit balance.');
            }

            $newBalance = (float) $customer->store_credit_balance - $amount;

            $entry = StoreCredit::create([
                'store_id' => $customer->store_id,
                'customer_id' => $customer->id,
                'type' => StoreCredit::TYPE_DEBIT,
                'amount' => $amount,
                'balance_after' => $newBalance,
                'source' => StoreCredit::SOURCE_CASH_OUT,
                'payout_method' => $payoutMethod,
                'description' => $description ?? "Cash out via {$payoutMethod}",
                'user_id' => $userId ?? auth()->id(),
            ]);

            $customer->update(['store_credit_balance' => $newBalance]);

            return $entry;
        });
    }

    /**
     * Get the current store credit balance for a customer.
     */
    public function getBalance(Customer $customer): float
    {
        return (float) $customer->store_credit_balance;
    }

    /**
     * Recalculate and sync the cached balance from the ledger.
     */
    public function recalculateBalance(Customer $customer): float
    {
        $credits = StoreCredit::where('customer_id', $customer->id)
            ->where('type', StoreCredit::TYPE_CREDIT)
            ->sum('amount');

        $debits = StoreCredit::where('customer_id', $customer->id)
            ->where('type', StoreCredit::TYPE_DEBIT)
            ->sum('amount');

        $balance = (float) $credits - (float) $debits;

        $customer->update(['store_credit_balance' => $balance]);

        return $balance;
    }
}
