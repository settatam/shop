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
            $table->string('status', 30)->default('open')->after('title');
            $table->string('channel', 30)->default('web')->after('status');
            $table->foreignId('assigned_agent_id')->nullable()->after('channel')->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable()->after('assigned_agent_id');
            $table->timestamp('closed_at')->nullable()->after('assigned_at');
            $table->string('external_thread_id')->nullable()->after('closed_at');

            $table->index('status');
            $table->index('channel');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('storefront_chat_sessions', function (Blueprint $table) {
            $table->dropForeign(['assigned_agent_id']);
            $table->dropIndex(['status']);
            $table->dropIndex(['channel']);
            $table->dropColumn([
                'status',
                'channel',
                'assigned_agent_id',
                'assigned_at',
                'closed_at',
                'external_thread_id',
            ]);
        });
    }
};
