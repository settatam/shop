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
        Schema::table('stores', function (Blueprint $table) {
            $table->string('email_from_address')->nullable()->after('customer_email');
            $table->string('email_from_name')->nullable()->after('email_from_address');
            $table->string('email_reply_to_address')->nullable()->after('email_from_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn([
                'email_from_address',
                'email_from_name',
                'email_reply_to_address',
            ]);
        });
    }
};
