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
        Schema::create('shipping_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->morphs('shippable');
            $table->string('type'); // 'outbound' or 'return'
            $table->string('carrier')->default('fedex');
            $table->string('tracking_number')->nullable();
            $table->string('service_type')->nullable();
            $table->string('label_format')->default('PDF');
            $table->string('label_path')->nullable();
            $table->text('label_zpl')->nullable();
            $table->json('shipment_details')->nullable();
            $table->json('sender_address')->nullable();
            $table->json('recipient_address')->nullable();
            $table->decimal('shipping_cost', 10, 2)->nullable();
            $table->string('status')->default('created');
            $table->string('fedex_shipment_id')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'tracking_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipping_labels');
    }
};
