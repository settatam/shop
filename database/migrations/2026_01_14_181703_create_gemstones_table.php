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
        Schema::create('gemstones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // diamond, ruby, sapphire, emerald, etc.
            $table->string('shape')->nullable(); // round, princess, oval, cushion, etc.
            $table->decimal('carat_weight', 8, 3)->nullable();
            $table->string('color_grade')->nullable(); // D-Z for diamonds, or color description
            $table->string('clarity_grade')->nullable(); // FL, IF, VVS1, VVS2, VS1, VS2, SI1, SI2, I1, I2, I3
            $table->string('cut_grade')->nullable(); // Excellent, Very Good, Good, Fair, Poor
            $table->decimal('length_mm', 8, 2)->nullable();
            $table->decimal('width_mm', 8, 2)->nullable();
            $table->decimal('depth_mm', 8, 2)->nullable();
            $table->string('origin')->nullable(); // Country of origin
            $table->string('treatment')->nullable(); // heat, irradiation, none, etc.
            $table->string('fluorescence')->nullable(); // None, Faint, Medium, Strong, Very Strong
            $table->foreignId('certification_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('estimated_value', 12, 2)->nullable();
            $table->json('metadata')->nullable(); // Additional properties
            $table->timestamps();

            $table->index(['store_id', 'type']);
            $table->index('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gemstones');
    }
};
