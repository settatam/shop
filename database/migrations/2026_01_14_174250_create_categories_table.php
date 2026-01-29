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
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('slug', 80)->nullable();
            $table->unsignedBigInteger('language_id')->default(1);
            $table->string('description', 191)->nullable();
            $table->string('meta_title', 191)->nullable();
            $table->string('meta_description', 191)->nullable();
            $table->string('meta_keyword', 191)->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->integer('sort_order')->nullable();
            $table->integer('column')->nullable();
            $table->tinyInteger('level')->default(0);
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('type')->nullable();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['store_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
