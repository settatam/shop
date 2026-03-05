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
        $tables = ['orders', 'transactions', 'memos', 'repairs'];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->foreignId('store_user_id')->nullable()->after('user_id')->constrained('store_users')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->after('store_user_id')->constrained('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = ['orders', 'transactions', 'memos', 'repairs'];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropConstrainedForeignId('store_user_id');
                $table->dropConstrainedForeignId('created_by');
            });
        }
    }
};
