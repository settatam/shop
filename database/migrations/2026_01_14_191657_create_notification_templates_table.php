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
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->index();
            $table->text('description')->nullable();
            $table->string('channel', 20); // email, sms, push
            $table->string('subject')->nullable(); // For email
            $table->text('content'); // Twig template content
            $table->json('available_variables')->nullable(); // Document available variables
            $table->string('category')->nullable(); // orders, products, customers, etc.
            $table->boolean('is_system')->default(false); // System templates cannot be deleted
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['store_id', 'slug', 'channel']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
