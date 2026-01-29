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
            // Drop the existing foreign key constraint to customers
            $table->dropForeign(['vendor_id']);
        });

        Schema::table('memos', function (Blueprint $table) {
            // Add new foreign key constraint to vendors
            $table->foreign('vendor_id')->references('id')->on('vendors')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('memos', function (Blueprint $table) {
            // Drop the foreign key to vendors
            $table->dropForeign(['vendor_id']);
        });

        Schema::table('memos', function (Blueprint $table) {
            // Restore foreign key to customers
            $table->foreign('vendor_id')->references('id')->on('customers')->nullOnDelete();
        });
    }
};
