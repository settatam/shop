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
        Schema::create('transactions_warehouse', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->unsignedBigInteger('legacy_store_id')->nullable();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('transaction_id')->nullable()->index();

            // Financial data
            $table->decimal('bought', 8, 2)->nullable();
            $table->decimal('estimated_value', 8, 2)->nullable();
            $table->decimal('final_offer', 10, 2)->nullable();
            $table->decimal('profit', 10, 2)->nullable();
            $table->decimal('profit_percent', 8, 2)->nullable();
            $table->decimal('estimated_profit', 8, 2)->nullable();
            $table->decimal('total_dwt', 8, 2)->nullable();

            // Transaction info
            $table->integer('number_of_transaction')->nullable();
            $table->integer('number_of_items')->default(0);
            $table->string('status')->nullable();
            $table->integer('status_id')->nullable();
            $table->string('source')->nullable();
            $table->string('payment_type')->nullable();
            $table->integer('payment_type_id')->nullable();

            // Customer info
            $table->integer('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_first_name')->nullable();
            $table->string('customer_last_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->timestamp('customer_since')->nullable();
            $table->string('is_repeat_customer', 30)->nullable();
            $table->integer('age')->nullable();
            $table->string('gender')->nullable();
            $table->date('dob')->nullable();
            $table->string('behavior')->nullable();

            // Address info
            $table->string('street_address')->nullable();
            $table->string('suite_apt')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip')->nullable();
            $table->integer('state_id')->nullable();
            $table->string('ip_address')->nullable();

            // Tracking
            $table->bigInteger('incoming_fedex')->nullable();
            $table->bigInteger('outgoing_fedex')->nullable();
            $table->bigInteger('incoming_tracking')->nullable();
            $table->bigInteger('outgoing_tracking')->nullable();
            $table->tinyInteger('transit_days')->default(0);

            // Dates - Kit lifecycle
            $table->timestamp('kit_request_date_time')->nullable()->index();
            $table->timestamp('kit_print_on')->nullable();
            $table->timestamp('kit_sent_date_time')->nullable()->index();
            $table->timestamp('kit_sent_not_received_date')->nullable();
            $table->timestamp('kit_received_ready_to_buy')->nullable();

            // Dates - Pending kit statuses
            $table->timestamp('pending_kit_confirmed_date_time')->nullable()->index();
            $table->timestamp('pending_kit_on_hold_date_time')->nullable()->index();
            $table->timestamp('pending_kit_returned_date_time')->nullable()->index();
            $table->timestamp('pending_kit_request_high_value')->nullable();
            $table->timestamp('pending_kit_request_high_value_watch_date_time')->nullable();
            $table->timestamp('pending_kit_request_bulk_date_time')->nullable();
            $table->timestamp('pending_kit_request_incomplete')->nullable();
            $table->timestamp('pending_kit_request_rejected_by_customer')->nullable();

            // Dates - Shipment
            $table->timestamp('shipment_received_on')->nullable();
            $table->timestamp('shipment_returned_on')->nullable();
            $table->timestamp('shipment_declined_on')->nullable();

            // Dates - Offers
            $table->timestamp('offer_given_on')->nullable();
            $table->timestamp('offer_given_date_time')->nullable();
            $table->timestamp('offer_accepted_on')->nullable();
            $table->timestamp('offer_accepted_date_time')->nullable()->index();
            $table->timestamp('offer_declined_date_time')->nullable()->index();
            $table->timestamp('offer_declined_send_back_date_time')->nullable()->index();
            $table->integer('offer_declined')->nullable();

            // Dates - Payment
            $table->timestamp('offer_paid_on')->nullable();
            $table->timestamp('payment_date_time')->nullable();

            // Dates - Other statuses
            $table->timestamp('received_date_time')->nullable()->index();
            $table->timestamp('received_rejected_date_time')->nullable()->index();
            $table->timestamp('returned_date_time')->nullable()->index();
            $table->timestamp('refused_by_fedex_date_time')->nullable()->index();
            $table->timestamp('reviewed_date_time')->nullable();
            $table->timestamp('on_hold_date_time')->nullable();
            $table->timestamp('hold_date')->nullable();
            $table->timestamp('sold_date_time')->nullable();
            $table->timestamp('melt_date_time')->nullable();
            $table->timestamp('customer_declined_date_time')->nullable();
            $table->timestamp('kit_rejected_hard_to_sell_date_time')->nullable();
            $table->timestamp('kit_rejected_high_markup_date_time')->nullable();

            // Payment details
            $table->string('paypal_address')->nullable();
            $table->string('venmo_address')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_address')->nullable();
            $table->string('bank_address_2')->nullable();
            $table->string('bank_address_city')->nullable();
            $table->string('bank_address_state_id')->nullable();
            $table->string('bank_address_zip')->nullable();
            $table->string('routing_number')->nullable();
            $table->string('account_number')->nullable();
            $table->string('account_name')->nullable();
            $table->string('account_type')->nullable();
            $table->string('check_name')->nullable();
            $table->string('check_address')->nullable();
            $table->string('check_address_2')->nullable();
            $table->string('check_city')->nullable();
            $table->string('check_zip')->nullable();
            $table->integer('check_state_id')->nullable();
            $table->string('check_state')->nullable();

            // Notes and metadata
            $table->string('inotes')->nullable();
            $table->string('cnotes')->nullable();
            $table->string('user_comment')->nullable();
            $table->string('images')->nullable();
            $table->string('keywords')->nullable();
            $table->text('tags')->nullable();
            $table->integer('tag_id')->nullable();
            $table->string('lead')->nullable();
            $table->integer('lead_id')->nullable();
            $table->string('website')->nullable();
            $table->string('store')->nullable();
            $table->integer('days_in_stock')->nullable();

            // Flags
            $table->boolean('is_accepted')->default(false)->index();
            $table->boolean('is_rejected')->default(false);
            $table->boolean('is_declined')->default(false);

            // SMS tracking
            $table->datetime('latest_incoming_sms_date')->nullable();
            $table->integer('latest_incoming_sms_id')->nullable();
            $table->boolean('latest_incoming_sms_is_read')->default(false);
            $table->string('latest_response_notification')->nullable();

            // Other
            $table->string('color')->nullable();
            $table->string('icon_color')->nullable();
            $table->string('timezone_id')->nullable();
            $table->tinyInteger('total_customer_received_transactions')->nullable()->default(0);
            $table->tinyInteger('total_customer_pending_transactions')->nullable()->default(0);
            $table->string('traffic_source')->nullable();
            $table->string('traffic_name')->nullable();
            $table->string('google_seo_client_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['legacy_id', 'legacy_store_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions_warehouse');
    }
};
