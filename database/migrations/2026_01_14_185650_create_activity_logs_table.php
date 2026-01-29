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
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('activity_slug'); // Reference to activities.slug
            $table->nullableMorphs('subject'); // The model being acted upon (e.g., Product, Order)
            $table->nullableMorphs('causer'); // What caused the activity (usually User, but could be system)
            $table->json('properties')->nullable(); // Additional context (old values, new values, etc.)
            $table->string('description')->nullable(); // Human-readable description
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'activity_slug']);
            $table->index(['store_id', 'user_id']);
            $table->index(['store_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
