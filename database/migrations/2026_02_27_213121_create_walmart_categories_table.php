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
        Schema::create('walmart_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('walmart_category_id', 50)->index();
            $table->tinyInteger('level')->default(0);
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->string('walmart_parent_id', 50)->nullable();
            $table->text('path')->nullable();
            $table->timestamps();

            $table->foreign('parent_id')->references('id')->on('walmart_categories')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('walmart_categories');
    }
};
