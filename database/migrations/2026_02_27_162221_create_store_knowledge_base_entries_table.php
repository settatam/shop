<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_knowledge_base_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('title');
            $table->text('content');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['store_id', 'type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_knowledge_base_entries');
    }
};
