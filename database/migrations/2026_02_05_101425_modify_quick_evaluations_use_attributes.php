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
        Schema::table('quick_evaluations', function (Blueprint $table) {
            $table->json('attributes')->nullable()->after('category_id');
            $table->dropColumn(['precious_metal', 'condition', 'estimated_weight']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quick_evaluations', function (Blueprint $table) {
            $table->string('precious_metal')->nullable()->after('category_id');
            $table->string('condition')->nullable()->after('precious_metal');
            $table->decimal('estimated_weight', 10, 4)->nullable()->after('condition');
            $table->dropColumn('attributes');
        });
    }
};
