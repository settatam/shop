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
        Schema::create('etsy_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('etsy_id')->index();
            $table->tinyInteger('level');
            $table->integer('etsy_parent_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->timestamps();

            $table->foreign('parent_id')->references('id')->on('etsy_categories')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('etsy_categories');
    }
};
