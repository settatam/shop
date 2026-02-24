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
        Schema::table('scheduled_reports', function (Blueprint $table) {
            $table->foreignId('template_id')->nullable()->after('report_type')
                ->constrained('notification_templates')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scheduled_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('template_id');
        });
    }
};
