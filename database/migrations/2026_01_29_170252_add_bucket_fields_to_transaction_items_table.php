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
        Schema::table('transaction_items', function (Blueprint $table) {
            if (! Schema::hasColumn('transaction_items', 'bucket_id')) {
                $table->foreignId('bucket_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            } else {
                // Column exists without FK, add the constraint
                $table->foreign('bucket_id')->references('id')->on('buckets')->nullOnDelete();
            }

            if (! Schema::hasColumn('transaction_items', 'is_added_to_bucket')) {
                $table->boolean('is_added_to_bucket')->default(false)->after('is_added_to_inventory');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transaction_items', function (Blueprint $table) {
            $table->dropForeign(['bucket_id']);
            $table->dropColumn(['bucket_id', 'is_added_to_bucket']);
        });
    }
};
