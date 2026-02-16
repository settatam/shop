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
        Schema::create('voice_commitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('voice_session_id')->nullable();
            $table->enum('commitment_type', ['follow_up', 'reminder', 'action', 'promise']);
            $table->text('description');
            $table->timestamp('due_at')->nullable();
            $table->enum('status', ['pending', 'completed', 'cancelled', 'overdue'])->default('pending');
            $table->string('related_entity_type', 100)->nullable();
            $table->unsignedBigInteger('related_entity_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status']);
            $table->index(['store_id', 'due_at']);
            $table->foreign('voice_session_id')->references('id')->on('voice_sessions')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voice_commitments');
    }
};
