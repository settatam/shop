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
        Schema::create('payment_terminals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();

            $table->string('name'); // "Front Counter Terminal"
            $table->string('gateway'); // square, dejavoo
            $table->string('device_id'); // External device identifier
            $table->string('device_code')->nullable(); // Pairing code for Square
            $table->string('location_id')->nullable(); // Square location ID

            $table->string('status')->default('pending'); // pending, active, inactive, disconnected
            $table->json('settings')->nullable(); // Gateway-specific settings
            $table->json('capabilities')->nullable(); // What the terminal supports

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('paired_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['store_id', 'device_id']);
            $table->index(['store_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_terminals');
    }
};
