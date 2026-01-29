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
        // Update all existing 'in_house' values to 'in_store'
        DB::table('transactions')
            ->where('type', 'in_house')
            ->update(['type' => 'in_store']);

        // Update the default value for the column
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('type')->default('in_store')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert back to 'in_house'
        DB::table('transactions')
            ->where('type', 'in_store')
            ->update(['type' => 'in_house']);

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('type')->default('in_house')->change();
        });
    }
};
