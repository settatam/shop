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
        Schema::table('store_marketplaces', function (Blueprint $table) {
            $table->boolean('is_app')->default(false)->after('status');
            $table->boolean('connected_successfully')->default(false)->after('is_app');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('store_marketplaces', function (Blueprint $table) {
            $table->dropColumn(['is_app', 'connected_successfully']);
        });
    }
};
