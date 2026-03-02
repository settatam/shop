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
        Schema::create('lead_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bucket_id')->nullable()->constrained()->nullOnDelete();

            $table->string('sku')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('buy_price', 10, 2)->nullable();
            $table->decimal('dwt', 10, 4)->nullable();
            $table->string('precious_metal')->nullable();
            $table->string('condition')->nullable();
            $table->json('attributes')->nullable();

            $table->boolean('is_added_to_inventory')->default(false);
            $table->boolean('is_added_to_bucket')->default(false);
            $table->timestamp('date_added_to_inventory')->nullable();

            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->json('ai_research')->nullable();
            $table->json('market_price_data')->nullable();
            $table->timestamp('ai_research_generated_at')->nullable();
            $table->json('web_search_results')->nullable();
            $table->timestamp('web_search_generated_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_items');
    }
};
