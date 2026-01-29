<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('memos', function (Blueprint $table) {
            // Drop the incorrect foreign key constraint
            $table->dropForeign(['vendor_id']);

            // Add the correct foreign key constraint to vendors table
            $table->foreign('vendor_id')->references('id')->on('vendors')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('memos', function (Blueprint $table) {
            // Drop the vendors foreign key
            $table->dropForeign(['vendor_id']);

            // Re-add the customers foreign key (original incorrect state)
            $table->foreign('vendor_id')->references('id')->on('customers')->nullOnDelete();
        });
    }
};
