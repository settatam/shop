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
            $table->string('repair_id_prefix', 20)->nullable()->after('buy_id_suffix');
            $table->string('repair_id_suffix', 20)->nullable()->after('repair_id_prefix');
            $table->string('memo_id_prefix', 20)->nullable()->after('repair_id_suffix');
            $table->string('memo_id_suffix', 20)->nullable()->after('memo_id_prefix');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['repair_id_prefix', 'repair_id_suffix', 'memo_id_prefix', 'memo_id_suffix']);
        });
    }
};
