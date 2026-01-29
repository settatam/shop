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
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_jewelry')->default(false)->after('is_published');
            $table->string('metal_type')->nullable()->after('is_jewelry'); // gold, silver, platinum, etc.
            $table->string('metal_purity')->nullable()->after('metal_type'); // 24k, 22k, 18k, 14k, 10k, sterling
            $table->string('metal_color')->nullable()->after('metal_purity'); // yellow, white, rose
            $table->decimal('metal_weight_grams', 10, 3)->nullable()->after('metal_color');
            $table->string('jewelry_type')->nullable()->after('metal_weight_grams'); // ring, necklace, bracelet, earring, pendant
            $table->string('jewelry_style')->nullable()->after('jewelry_type'); // engagement, wedding, fashion, vintage
            $table->decimal('ring_size', 4, 2)->nullable()->after('jewelry_style');
            $table->decimal('chain_length_inches', 5, 2)->nullable()->after('ring_size');
            $table->string('clasp_type')->nullable()->after('chain_length_inches');
            $table->boolean('has_gemstones')->default(false)->after('clasp_type');
            $table->string('main_stone_type')->nullable()->after('has_gemstones');
            $table->decimal('total_carat_weight', 8, 3)->nullable()->after('main_stone_type');
            $table->foreignId('primary_certification_id')->nullable()->after('total_carat_weight');
            $table->json('jewelry_metadata')->nullable()->after('primary_certification_id');

            $table->index(['store_id', 'is_jewelry']);
            $table->index(['metal_type', 'metal_purity']);
            $table->index('jewelry_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'is_jewelry']);
            $table->dropIndex(['metal_type', 'metal_purity']);
            $table->dropIndex(['jewelry_type']);

            $table->dropColumn([
                'is_jewelry',
                'metal_type',
                'metal_purity',
                'metal_color',
                'metal_weight_grams',
                'jewelry_type',
                'jewelry_style',
                'ring_size',
                'chain_length_inches',
                'clasp_type',
                'has_gemstones',
                'main_stone_type',
                'total_carat_weight',
                'primary_certification_id',
                'jewelry_metadata',
            ]);
        });
    }
};
