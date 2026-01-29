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
        Schema::table('transactions', function (Blueprint $table) {
            // Customer's description of what they're sending
            $table->text('customer_description')->nullable()->after('internal_notes');

            // Customer's expected amount
            $table->decimal('customer_amount', 10, 2)->nullable()->after('customer_description');

            // Categories customer selected (from form)
            $table->string('customer_categories')->nullable()->after('customer_amount');

            // Kit/Shipping tracking
            $table->string('outbound_tracking_number')->nullable()->after('customer_categories');
            $table->string('outbound_carrier')->default('fedex')->after('outbound_tracking_number');
            $table->string('return_tracking_number')->nullable()->after('outbound_carrier');
            $table->string('return_carrier')->default('fedex')->after('return_tracking_number');

            // Timestamps for online workflow
            $table->timestamp('kit_sent_at')->nullable()->after('return_carrier');
            $table->timestamp('kit_delivered_at')->nullable()->after('kit_sent_at');
            $table->timestamp('items_received_at')->nullable()->after('kit_delivered_at');
            $table->timestamp('items_reviewed_at')->nullable()->after('items_received_at');
            $table->timestamp('return_shipped_at')->nullable()->after('items_reviewed_at');
            $table->timestamp('return_delivered_at')->nullable()->after('return_shipped_at');

            // Assigned user for processing
            $table->foreignId('assigned_to')->nullable()->after('user_id')->constrained('users')->nullOnDelete();

            // Source of the transaction (paperform, website, etc.)
            $table->string('source')->nullable()->after('type');

            // Index for common queries
            $table->index(['type', 'status']);
            $table->index('source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['type', 'status']);
            $table->dropIndex(['source']);

            $table->dropForeign(['assigned_to']);

            $table->dropColumn([
                'customer_description',
                'customer_amount',
                'customer_categories',
                'outbound_tracking_number',
                'outbound_carrier',
                'return_tracking_number',
                'return_carrier',
                'kit_sent_at',
                'kit_delivered_at',
                'items_received_at',
                'items_reviewed_at',
                'return_shipped_at',
                'return_delivered_at',
                'assigned_to',
                'source',
            ]);
        });
    }
};
