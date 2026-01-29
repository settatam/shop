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
            $table->string('buy_id_prefix', 20)->nullable()->after('order_id_suffix');
            $table->string('buy_id_suffix', 20)->nullable()->after('buy_id_prefix');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['buy_id_prefix', 'buy_id_suffix']);
        });
    }
};
