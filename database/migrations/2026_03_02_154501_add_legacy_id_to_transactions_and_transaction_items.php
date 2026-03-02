<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_id')->nullable()->after('id')->index();
        });

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_id')->nullable()->after('id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('legacy_id');
        });

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->dropColumn('legacy_id');
        });
    }
};
