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
            $table->dropIndex(['expires_at']);
            $table->dropColumn('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('storefront_chat_sessions', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable();
            $table->index('expires_at');
        });
    }
};
