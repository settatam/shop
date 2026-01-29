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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 191)->nullable();
            $table->string('last_name', 191)->nullable();
            $table->string('email')->nullable();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->boolean('accepts_marketing')->default(false);
            $table->boolean('is_active')->default(false);
            $table->string('password', 90)->nullable();
            $table->rememberToken();
            $table->timestamp('email_verified_at')->nullable();
            $table->boolean('notify')->default(false);
            $table->string('city')->nullable();
            $table->unsignedBigInteger('country_id')->nullable();
            $table->unsignedBigInteger('state_id')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('address')->nullable();
            $table->string('address2')->nullable();
            $table->string('zip')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ethnicity')->nullable();
            $table->string('photo')->nullable();
            $table->json('additional_fields')->nullable();
            $table->integer('number_of_sales')->default(0);
            $table->integer('number_of_buys')->default(0);
            $table->date('last_sales_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('store_id');
            $table->index('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
