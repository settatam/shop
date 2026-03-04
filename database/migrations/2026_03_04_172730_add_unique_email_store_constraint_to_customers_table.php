<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Normalize empty strings to NULL so they don't conflict with the unique constraint
        DB::table('customers')->where('email', '')->update(['email' => null]);

        // Remove duplicates: keep the oldest customer (lowest id), reassign references from newer duplicates
        $duplicates = DB::select('
            SELECT store_id, email, GROUP_CONCAT(id ORDER BY id) as ids
            FROM customers
            WHERE email IS NOT NULL
            GROUP BY store_id, email
            HAVING COUNT(*) > 1
        ');

        foreach ($duplicates as $dupe) {
            $ids = explode(',', $dupe->ids);
            $keepId = (int) array_shift($ids);
            $removeIds = array_map('intval', $ids);

            // Reassign foreign key references to the kept customer
            foreach (['orders', 'transactions', 'memos', 'repairs', 'invoices', 'notes', 'customer_documents'] as $table) {
                if (Schema::hasColumn($table, 'customer_id')) {
                    DB::table($table)
                        ->whereIn('customer_id', $removeIds)
                        ->update(['customer_id' => $keepId]);
                }
            }

            // Delete the duplicate customers
            DB::table('customers')->whereIn('id', $removeIds)->delete();
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->unique(['store_id', 'email'], 'customers_store_id_email_unique');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('customers_store_id_email_unique');
        });
    }
};
