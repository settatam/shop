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
        // Skip for SQLite (test database) - it doesn't support foreign keys the same way
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        // Check if vendor_id foreign key already exists and references vendors
        $existingFk = DB::select("
            SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'repairs'
            AND COLUMN_NAME = 'vendor_id'
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ");

        // If foreign key exists and references the wrong table (customers), drop it
        if (! empty($existingFk) && $existingFk[0]->REFERENCED_TABLE_NAME === 'customers') {
            Schema::table('repairs', function (Blueprint $table) {
                $table->dropForeign(['vendor_id']);
            });
        }

        // If no foreign key exists or we just dropped one, add the correct one
        if (empty($existingFk) || $existingFk[0]->REFERENCED_TABLE_NAME !== 'vendors') {
            Schema::table('repairs', function (Blueprint $table) {
                $table->foreign('vendor_id')
                    ->references('id')
                    ->on('vendors')
                    ->onDelete('set null');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Skip for SQLite (test database)
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('repairs', function (Blueprint $table) {
            $table->dropForeign(['vendor_id']);

            $table->foreign('vendor_id')
                ->references('id')
                ->on('customers')
                ->onDelete('set null');
        });
    }
};
