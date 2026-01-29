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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('title', 191)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('weight', 10, 4)->nullable();
            $table->string('weight_unit', 191)->nullable();
            $table->decimal('compare_at_price', 10, 2)->nullable();
            $table->string('currency_code', 191)->nullable();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('handle', 170);
            $table->string('upc', 12)->nullable();
            $table->string('ean', 14)->nullable();
            $table->string('jan', 13)->nullable();
            $table->string('isbn', 17)->nullable();
            $table->string('mpn', 64)->nullable();
            $table->string('location', 128)->nullable();
            $table->unsignedBigInteger('manufacturer_id')->nullable();
            $table->string('tax_class', 191)->nullable();
            $table->dateTime('date_available')->nullable();
            $table->decimal('length', 10, 2)->nullable();
            $table->decimal('width', 10, 2)->nullable();
            $table->decimal('height', 10, 2)->nullable();
            $table->string('length_class', 20)->nullable();
            $table->tinyInteger('minimum_order')->default(1);
            $table->smallInteger('views')->nullable();
            $table->tinyInteger('sort_order')->nullable();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sort_attribute', 50)->nullable();
            $table->boolean('has_variants')->default(false);
            $table->string('country_of_origin', 100)->nullable();
            $table->string('step', 45)->default('general');
            $table->tinyInteger('is_published')->default(0);
            $table->string('is_draft', 45)->default('0');
            $table->text('seo_description')->nullable();
            $table->string('seo_page_title', 45)->nullable();
            $table->tinyInteger('track_quantity')->default(0);
            $table->tinyInteger('sell_out_of_stock')->default(0);
            $table->integer('quantity')->default(0);
            $table->unsignedBigInteger('custom_product_type_id')->nullable();
            $table->unsignedBigInteger('product_type_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('store_id');
            $table->unique(['store_id', 'handle']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
