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
        Schema::create('quick_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('precious_metal')->nullable();
            $table->string('condition')->nullable();
            $table->decimal('estimated_weight', 10, 4)->nullable();
            $table->decimal('estimated_value', 10, 2)->nullable();
            $table->json('similar_items')->nullable();
            $table->json('ai_research')->nullable();
            $table->timestamp('ai_research_generated_at')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();

            $table->index(['store_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quick_evaluations');
    }
};
