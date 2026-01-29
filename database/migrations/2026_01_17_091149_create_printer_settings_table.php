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
        Schema::create('printer_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('printer_type')->default('zebra'); // zebra, godex, other
            $table->unsignedInteger('top_offset')->default(30);
            $table->unsignedInteger('left_offset')->default(0);
            $table->unsignedInteger('right_offset')->default(0);
            $table->unsignedInteger('text_size')->default(20);
            $table->unsignedInteger('barcode_height')->default(50);
            $table->unsignedInteger('line_height')->default(25);
            $table->unsignedInteger('label_width')->default(406); // 2 inches at 203 dpi
            $table->unsignedInteger('label_height')->default(203); // 1 inch at 203 dpi
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['store_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('printer_settings');
    }
};
