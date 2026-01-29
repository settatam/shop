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
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 191)->unique();
            $table->string('url', 191)->nullable();
            $table->string('name', 191)->nullable();
            $table->string('account_email', 191)->nullable();
            $table->string('customer_email', 191)->nullable();
            $table->string('business_name', 191)->nullable();
            $table->string('address', 191)->nullable();
            $table->string('address2', 191)->nullable();
            $table->string('city', 191)->nullable();
            $table->string('state', 191)->nullable();
            $table->unsignedBigInteger('country_id')->nullable();
            $table->unsignedBigInteger('timezone_id')->nullable();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->unsignedBigInteger('default_weight_unit_id')->nullable();
            $table->unsignedBigInteger('currency_id')->nullable();
            $table->unsignedBigInteger('theme_id')->nullable();
            $table->boolean('is_active')->default(false);
            $table->text('meta_description')->nullable();
            $table->text('meta_title')->nullable();
            $table->string('phone', 15)->nullable();
            $table->string('zip', 10)->nullable();
            $table->string('store_domain', 90)->nullable();
            $table->unsignedBigInteger('industry_id')->nullable();
            $table->string('order_id_suffix', 45)->nullable();
            $table->string('order_id_prefix', 45)->nullable();
            $table->tinyInteger('gift_card_should_expire')->default(1);
            $table->integer('gift_card_expire_after')->nullable();
            $table->string('gift_card_expiry_duration', 45)->nullable();
            $table->unsignedBigInteger('store_plan_id')->default(1);
            $table->timestamp('last_payment_date')->nullable();
            $table->timestamp('next_payment_date')->nullable();
            $table->tinyInteger('allow_guest_checkout')->default(0);
            $table->tinyInteger('login_wall')->default(0);
            $table->unsignedBigInteger('payment_system_id')->default(1);
            $table->tinyInteger('enable_store_pickup')->default(0);
            $table->tinyInteger('enable_pay_on_delivery')->default(0);
            $table->tinyInteger('step')->default(1);
            $table->unsignedBigInteger('state_id')->nullable();
            $table->boolean('jewelry_module_enabled')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
