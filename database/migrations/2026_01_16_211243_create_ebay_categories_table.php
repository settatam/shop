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
        Schema::create('ebay_categories', function (Blueprint $table) {
            $table->id();
            $table->text('name')->nullable();
            $table->tinyInteger('level')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->integer('ebay_parent_id')->nullable();
            $table->integer('ebay_category_id')->nullable()->index();
            $table->text('comments')->nullable();
            $table->timestamps();

            $table->foreign('parent_id')->references('id')->on('ebay_categories')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ebay_categories');
    }
};
