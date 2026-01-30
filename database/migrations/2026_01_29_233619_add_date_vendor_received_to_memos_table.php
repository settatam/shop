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
        Schema::table('memos', function (Blueprint $table) {
            $table->timestamp('date_sent_to_vendor')->nullable()->after('duration');
            $table->timestamp('date_vendor_received')->nullable()->after('date_sent_to_vendor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('memos', function (Blueprint $table) {
            $table->dropColumn(['date_sent_to_vendor', 'date_vendor_received']);
        });
    }
};
