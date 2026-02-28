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
        Schema::table('storefront_chat_sessions', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('store_marketplace_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('storefront_chat_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });
    }
};
