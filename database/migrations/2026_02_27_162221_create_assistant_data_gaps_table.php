<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_data_gaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('field_name');
            $table->text('question_context')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('last_occurred_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'product_id', 'field_name']);
            $table->index(['store_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_data_gaps');
    }
};
