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
        Schema::create('return_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('return_window_days')->default(30);
            $table->boolean('allow_refund')->default(true);
            $table->boolean('allow_store_credit')->default(true);
            $table->boolean('allow_exchange')->default(true);
            $table->decimal('restocking_fee_percent', 5, 2)->default(0);
            $table->boolean('require_receipt')->default(false);
            $table->boolean('require_original_packaging')->default(false);
            $table->json('excluded_conditions')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('return_policies');
    }
};
