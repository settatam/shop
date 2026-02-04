<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('status')->default('draft')->after('is_published');
            $table->index(['store_id', 'status']);
        });

        // Migrate existing data: convert is_published/is_draft to status
        DB::table('products')
            ->where('is_draft', '1')
            ->update(['status' => 'draft']);

        DB::table('products')
            ->where('is_published', true)
            ->where(function ($q) {
                $q->where('is_draft', '!=', '1')->orWhereNull('is_draft');
            })
            ->update(['status' => 'active']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'status']);
            $table->dropColumn('status');
        });
    }
};
