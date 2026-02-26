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
        Schema::create('category_platform_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_marketplace_id')->constrained()->cascadeOnDelete();
            $table->string('platform');
            $table->string('primary_category_id');
            $table->string('primary_category_name');
            $table->string('secondary_category_id')->nullable();
            $table->string('secondary_category_name')->nullable();
            $table->timestamp('item_specifics_synced_at')->nullable();
            $table->text('field_mappings')->nullable();
            $table->text('default_values')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['category_id', 'store_marketplace_id'], 'cat_platform_mapping_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('category_platform_mappings');
    }
};
