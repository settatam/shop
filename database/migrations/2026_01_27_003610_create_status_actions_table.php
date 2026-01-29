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
        Schema::create('status_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('status_id')->constrained()->cascadeOnDelete();
            $table->string('action_type', 50); // change_status, print_shipping_label, print_barcode, print_return_label, delete, export, add_tag, remove_tag
            $table->string('name', 100);
            $table->string('icon', 50)->nullable();
            $table->string('color', 20)->nullable(); // For button styling
            $table->json('config')->nullable(); // Action-specific config
            $table->boolean('is_bulk')->default(true);
            $table->boolean('requires_confirmation')->default(false);
            $table->string('confirmation_message', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->index(['status_id', 'is_enabled', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('status_actions');
    }
};
