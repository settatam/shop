<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Status mapping from old to new values.
     *
     * @var array<string, string>
     */
    protected array $statusMapping = [
        'draft' => 'not_listed',
        'not_for_sale' => 'not_listed',
        'active' => 'listed',
        'unlisted' => 'ended',
    ];

    /**
     * Reverse status mapping for rollback.
     *
     * @var array<string, string>
     */
    protected array $reverseStatusMapping = [
        'not_listed' => 'draft',
        'listed' => 'active',
        'ended' => 'unlisted',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->statusMapping as $oldStatus => $newStatus) {
            DB::table('platform_listings')
                ->where('status', $oldStatus)
                ->update(['status' => $newStatus]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->reverseStatusMapping as $newStatus => $oldStatus) {
            DB::table('platform_listings')
                ->where('status', $newStatus)
                ->update(['status' => $oldStatus]);
        }
    }
};
