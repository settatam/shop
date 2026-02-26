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
        Schema::table('sales_channels', function (Blueprint $table) {
            $table->boolean('auto_list')->default(false)->after('is_default');
        });

        // Backfill: local channels should auto-list by default
        DB::table('sales_channels')->where('is_local', true)->update(['auto_list' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_channels', function (Blueprint $table) {
            $table->dropColumn('auto_list');
        });
    }
};
