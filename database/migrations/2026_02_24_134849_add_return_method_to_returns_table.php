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
        Schema::table('returns', function (Blueprint $table) {
            $table->string('return_method', 20)->default('in_store')->after('type');
            $table->boolean('items_restocked')->default(false)->after('received_at');
            $table->timestamp('restocked_at')->nullable()->after('items_restocked');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropColumn(['return_method', 'items_restocked', 'restocked_at']);
        });
    }
};
