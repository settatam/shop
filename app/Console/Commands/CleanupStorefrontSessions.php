<?php

namespace App\Console\Commands;

use App\Models\StorefrontChatSession;
use Illuminate\Console\Command;

class CleanupStorefrontSessions extends Command
{
    protected $signature = 'storefront:cleanup-sessions {--hours=24 : Hours of inactivity before cleanup}';

    protected $description = 'Delete expired storefront chat sessions and their messages';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $cutoff = now()->subHours($hours);

        $count = StorefrontChatSession::where('expires_at', '<', $cutoff)
            ->orWhere(function ($query) use ($cutoff) {
                $query->whereNull('last_message_at')
                    ->where('created_at', '<', $cutoff);
            })
            ->delete();

        $this->info("Deleted {$count} expired storefront chat sessions.");

        return self::SUCCESS;
    }
}
