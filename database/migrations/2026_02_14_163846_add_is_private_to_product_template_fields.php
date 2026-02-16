<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_template_fields', function (Blueprint $table) {
            $table->boolean('is_private')->default(false)->after('is_required')
                ->comment('Private fields are for internal use only and not sent to platforms');
        });
    }

    public function down(): void
    {
        Schema::table('product_template_fields', function (Blueprint $table) {
            $table->dropColumn('is_private');
        });
    }
};
