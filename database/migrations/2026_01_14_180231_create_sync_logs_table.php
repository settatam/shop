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
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_marketplace_id')->constrained('store_marketplaces')->cascadeOnDelete();
            $table->string('sync_type'); // products, orders, inventory, full
            $table->string('direction'); // push, pull
            $table->string('status'); // pending, running, completed, failed
            $table->integer('total_items')->default(0);
            $table->integer('processed_items')->default(0);
            $table->integer('success_count')->default(0);
            $table->integer('error_count')->default(0);
            $table->json('errors')->nullable(); // Array of error messages
            $table->json('summary')->nullable(); // Sync summary data
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['store_marketplace_id', 'sync_type']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
