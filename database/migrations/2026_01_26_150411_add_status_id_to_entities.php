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
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('status_id')->nullable()->after('status')->constrained('statuses')->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('status_id')->nullable()->after('status')->constrained('statuses')->nullOnDelete();
        });

        Schema::table('repairs', function (Blueprint $table) {
            $table->foreignId('status_id')->nullable()->after('status')->constrained('statuses')->nullOnDelete();
        });

        Schema::table('memos', function (Blueprint $table) {
            $table->foreignId('status_id')->nullable()->after('status')->constrained('statuses')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('status_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('status_id');
        });

        Schema::table('repairs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('status_id');
        });

        Schema::table('memos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('status_id');
        });
    }
};
