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
        Schema::table('categories', function (Blueprint $table) {
            $table->string('sku_suffix', 50)->nullable()->after('sku_prefix');
            $table->foreignId('default_bucket_id')->nullable()->after('sku_suffix')
                ->constrained('buckets')->nullOnDelete();
            $table->json('barcode_attributes')->nullable()->after('default_bucket_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign(['default_bucket_id']);
            $table->dropColumn(['sku_suffix', 'default_bucket_id', 'barcode_attributes']);
        });
    }
};
