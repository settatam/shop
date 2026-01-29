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
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();

            // Polymorphic relationship (Customer, Store, Transaction, etc.)
            $table->nullableMorphs('addressable');

            // Name fields
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('company', 255)->nullable();
            $table->string('nickname', 100)->nullable();

            // Address fields
            $table->string('address', 255)->nullable();
            $table->string('address2', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->unsignedBigInteger('state_id')->nullable();
            $table->unsignedBigInteger('country_id')->nullable();
            $table->string('zip', 20)->nullable();

            // Contact fields
            $table->string('phone', 50)->nullable();
            $table->string('extension', 10)->nullable();

            // Address type flags
            $table->boolean('is_default')->default(false);
            $table->boolean('is_shipping')->default(true);
            $table->boolean('is_billing')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->string('type', 50)->nullable(); // e.g., 'home', 'work', 'shipping'

            // Geolocation (optional)
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('location_type', 50)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['addressable_type', 'addressable_id', 'is_default']);
            $table->index(['store_id', 'addressable_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
