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
        Schema::create('marketplace_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_marketplace_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // return, payment, fulfillment
            $table->string('external_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('details')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['store_marketplace_id', 'type', 'external_id'], 'mp_marketplace_type_external_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketplace_policies');
    }
};
