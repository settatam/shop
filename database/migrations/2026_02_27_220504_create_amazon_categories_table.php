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
        Schema::create('amazon_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('amazon_category_id', 100)->index();
            $table->tinyInteger('level')->default(0);
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->string('amazon_parent_id', 100)->nullable();
            $table->text('path')->nullable();
            $table->timestamps();

            $table->foreign('parent_id')->references('id')->on('amazon_categories')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('amazon_categories');
    }
};
