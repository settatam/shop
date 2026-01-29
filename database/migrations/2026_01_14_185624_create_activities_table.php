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
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique(); // e.g., "products.create", "orders.view"
            $table->string('name'); // e.g., "Create Products"
            $table->string('description')->nullable();
            $table->string('category'); // e.g., "products", "orders", "inventory"
            $table->string('group')->nullable(); // e.g., "management", "reporting"
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
