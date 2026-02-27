<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_marketplace_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('name')->default('Default');
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['token', 'is_active']);
            $table->index('store_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_api_tokens');
    }
};
