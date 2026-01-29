<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Consolidate transaction types:
     * - 'in_store' -> 'in_house' (same concept, different naming)
     * - 'buy' -> 'in_house' (legacy type, same as in_house)
     */
    public function up(): void
    {
        // Update 'in_store' to 'in_house'
        DB::table('transactions')
            ->where('type', 'in_store')
            ->update(['type' => 'in_house']);

        // Update 'buy' to 'in_house'
        DB::table('transactions')
            ->where('type', 'buy')
            ->update(['type' => 'in_house']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Cannot reliably reverse this migration since we don't know
        // which records were originally 'in_store' vs 'buy'
    }
};
