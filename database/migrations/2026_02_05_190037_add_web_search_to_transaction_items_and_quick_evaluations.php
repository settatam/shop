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
        Schema::table('transaction_items', function (Blueprint $table) {
            $table->json('web_search_results')->nullable()->after('ai_research_generated_at');
            $table->timestamp('web_search_generated_at')->nullable()->after('web_search_results');
        });

        Schema::table('quick_evaluations', function (Blueprint $table) {
            $table->json('web_search_results')->nullable()->after('ai_research_generated_at');
            $table->timestamp('web_search_generated_at')->nullable()->after('web_search_results');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transaction_items', function (Blueprint $table) {
            $table->dropColumn(['web_search_results', 'web_search_generated_at']);
        });

        Schema::table('quick_evaluations', function (Blueprint $table) {
            $table->dropColumn(['web_search_results', 'web_search_generated_at']);
        });
    }
};
