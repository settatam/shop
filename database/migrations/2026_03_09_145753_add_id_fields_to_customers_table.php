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
        Schema::table('customers', function (Blueprint $table) {
            $table->string('id_number', 100)->nullable()->after('zip');
            $table->string('id_issuing_state', 5)->nullable()->after('id_number');
            $table->date('id_expiration_date')->nullable()->after('id_issuing_state');
            $table->date('date_of_birth')->nullable()->after('id_expiration_date');
            $table->index(['store_id', 'id_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'id_number']);
            $table->dropColumn(['id_number', 'id_issuing_state', 'id_expiration_date', 'date_of_birth']);
        });
    }
};
