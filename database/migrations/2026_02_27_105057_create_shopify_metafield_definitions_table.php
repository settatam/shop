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
        Schema::create('shopify_metafield_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_marketplace_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('namespace')->default('custom');
            $table->string('name');
            $table->string('type');
            $table->text('description')->nullable();
            $table->string('shopify_gid');
            $table->timestamps();

            $table->unique(['store_marketplace_id', 'namespace', 'key'], 'shopify_metafield_defs_marketplace_ns_key_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shopify_metafield_definitions');
    }
};
