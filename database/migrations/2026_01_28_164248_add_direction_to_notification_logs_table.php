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
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->string('direction', 20)->default('outbound')->after('channel');
            $table->index(['notifiable_type', 'notifiable_id', 'channel', 'direction'], 'notif_logs_notifiable_channel_dir_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->dropIndex('notif_logs_notifiable_channel_dir_idx');
            $table->dropColumn('direction');
        });
    }
};
