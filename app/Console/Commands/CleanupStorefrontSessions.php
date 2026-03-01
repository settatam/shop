<?php

namespace App\Console\Commands;

use App\Models\StorefrontChatSession;
use Illuminate\Console\Command;

class CleanupStorefrontSessions extends Command
{
    protected $signature = 'storefront:cleanup-sessions {--hours=24 : Hours of inactivity before cleanup}';

    protected $description = 'Delete empty storefront chat sessions with no messages';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $cutoff = now()->subHours($hours);

        $count = StorefrontChatSession::whereNull('last_message_at')
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Deleted {$count} empty storefront chat sessions.");

        return self::SUCCESS;
    }
}
