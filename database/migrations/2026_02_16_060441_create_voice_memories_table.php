<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('voice_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->enum('memory_type', ['fact', 'preference', 'commitment', 'context']);
            $table->string('category', 100)->nullable();
            $table->text('content');
            $table->decimal('confidence', 3, 2)->default(1.00);
            $table->string('source', 100)->nullable();
            $table->string('source_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['store_id', 'memory_type']);
            $table->index(['store_id', 'category']);

            // Only add fulltext index for MySQL/MariaDB
            if (DB::connection()->getDriverName() === 'mysql') {
                $table->fullText('content');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voice_memories');
    }
};
