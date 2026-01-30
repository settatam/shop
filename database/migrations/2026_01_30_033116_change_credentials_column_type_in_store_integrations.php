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
        // Change credentials from json to text to support Laravel's encrypted:array cast
        // The encrypted cast stores an encrypted string, not valid JSON
        Schema::table('store_integrations', function (Blueprint $table) {
            $table->text('credentials')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('store_integrations', function (Blueprint $table) {
            $table->json('credentials')->nullable()->change();
        });
    }
};
