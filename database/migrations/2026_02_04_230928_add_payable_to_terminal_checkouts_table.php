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
        Schema::table('terminal_checkouts', function (Blueprint $table) {
            // Add polymorphic columns for any payable model (Order, Repair, Memo, Layaway)
            $table->nullableMorphs('payable');
            $table->json('metadata')->nullable()->after('gateway_response');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('terminal_checkouts', function (Blueprint $table) {
            $table->dropMorphs('payable');
            $table->dropColumn('metadata');
        });
    }
};
