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
        Schema::create('notification_layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->index();
            $table->string('channel', 20);
            $table->longText('content');
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_system')->default(false);
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
        Schema::dropIfExists('notification_layouts');
    }
};
