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
        Schema::create('ebay_item_specifics', function (Blueprint $table) {
            $table->id();
            $table->integer('ebay_category_id')->index();
            $table->string('name')->nullable();
            $table->string('type')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_recommended')->default(false);
            $table->string('aspect_mode')->nullable();
            $table->boolean('is_condition_descriptor')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ebay_item_specifics');
    }
};
