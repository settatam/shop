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
            $table->string('sku_format', 255)->nullable()->after('template_id');
            $table->string('sku_prefix', 50)->nullable()->after('sku_format');
            $table->foreignId('label_template_id')->nullable()->after('sku_prefix')
                ->constrained('label_templates')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign(['label_template_id']);
            $table->dropColumn(['sku_format', 'sku_prefix', 'label_template_id']);
        });
    }
};
