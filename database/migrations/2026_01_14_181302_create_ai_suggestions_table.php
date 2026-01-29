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
        Schema::create('ai_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->morphs('suggestable'); // product_id, listing_id, etc.
            $table->string('type'); // description, title, category, price
            $table->string('platform')->nullable(); // Target platform
            $table->text('original_content')->nullable();
            $table->text('suggested_content');
            $table->json('metadata')->nullable(); // Additional context/reasoning
            $table->string('status')->default('pending'); // pending, accepted, rejected
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'type']);
            $table->index(['suggestable_type', 'suggestable_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_suggestions');
    }
};
