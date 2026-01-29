<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->nullableMorphs('payable');
            $table->index(['payable_type', 'payable_id', 'store_id']);
        });

        // Migrate existing memo_id to payable
        DB::table('payments')
            ->whereNotNull('memo_id')
            ->update([
                'payable_type' => 'App\\Models\\Memo',
                'payable_id' => DB::raw('memo_id'),
            ]);

        // Migrate existing order_id to payable (only if not already a memo payment)
        DB::table('payments')
            ->whereNull('payable_type')
            ->whereNotNull('order_id')
            ->update([
                'payable_type' => 'App\\Models\\Order',
                'payable_id' => DB::raw('order_id'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['payable_type', 'payable_id', 'store_id']);
            $table->dropMorphs('payable');
        });
    }
};
