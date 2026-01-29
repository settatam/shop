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
        Schema::table('store_users', function (Blueprint $table) {
            // Drop foreign key first
            $table->dropForeign(['user_id']);
            // Then drop the unique constraint
            $table->dropUnique(['user_id', 'store_id']);
        });

        Schema::table('store_users', function (Blueprint $table) {
            // Modify user_id to be nullable
            $table->unsignedBigInteger('user_id')->nullable()->change();

            // Re-add foreign key with nullable constraint
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            // Add unique index on email + store_id (handles pending invitations)
            $table->unique(['email', 'store_id'], 'store_users_email_store_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('store_users', function (Blueprint $table) {
            $table->dropUnique(['email', 'store_id']);
            $table->dropForeign(['user_id']);
        });

        Schema::table('store_users', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'store_id']);
        });
    }
};
