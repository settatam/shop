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
        Schema::create('store_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('provider'); // paypal, fedex, quickbooks, etc.
            $table->string('name')->nullable(); // optional friendly name
            $table->string('environment')->default('sandbox'); // sandbox, production
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('credentials')->nullable(); // client_id, client_secret, account_number, etc.
            $table->json('settings')->nullable(); // provider-specific settings
            $table->string('status')->default('active'); // active, inactive, error
            $table->text('last_error')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['store_id', 'provider', 'environment']);
            $table->index(['store_id', 'provider']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_integrations');
    }
};
