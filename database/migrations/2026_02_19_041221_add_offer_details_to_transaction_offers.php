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
        Schema::table('transaction_offers', function (Blueprint $table) {
            $table->text('reasoning')->nullable()->after('amount');
            $table->json('images')->nullable()->after('reasoning');
            $table->string('tier', 20)->nullable()->after('images'); // 'good', 'better', 'best'
            $table->timestamp('expires_at')->nullable()->after('tier');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transaction_offers', function (Blueprint $table) {
            $table->dropColumn(['reasoning', 'images', 'tier', 'expires_at']);
        });
    }
};
