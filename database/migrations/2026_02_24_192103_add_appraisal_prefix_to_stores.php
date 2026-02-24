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
            $table->string('appraisal_id_prefix', 20)->default('APR')->after('repair_id_suffix');
            $table->string('appraisal_id_suffix', 20)->nullable()->after('appraisal_id_prefix');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['appraisal_id_prefix', 'appraisal_id_suffix']);
        });
    }
};
