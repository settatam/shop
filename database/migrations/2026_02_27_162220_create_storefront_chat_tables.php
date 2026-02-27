<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_chat_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_marketplace_id')->constrained()->cascadeOnDelete();
            $table->string('visitor_id', 64);
            $table->string('title')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'visitor_id']);
            $table->index('expires_at');
        });

        Schema::create('storefront_chat_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('storefront_chat_session_id')
                ->constrained('storefront_chat_sessions')
                ->cascadeOnDelete();
            $table->string('role', 20);
            $table->text('content');
            $table->json('tool_calls')->nullable();
            $table->json('tool_results')->nullable();
            $table->unsignedInteger('tokens_used')->default(0);
            $table->timestamps();

            $table->index('storefront_chat_session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_chat_messages');
        Schema::dropIfExists('storefront_chat_sessions');
    }
};
