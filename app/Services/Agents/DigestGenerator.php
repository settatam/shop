<?php

namespace App\Services\Agents;

use App\Models\AgentAction;
use App\Models\AgentRun;
use App\Models\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

class DigestGenerator
{
    /**
     * Generate digest data for a store.
     *
     * @return array{
     *   runs_total: int,
     *   runs_successful: int,
     *   runs_failed: int,
     *   actions_executed: int,
     *   actions_pending: int,
     *   actions_rejected: int,
     *   highlights: array,
     *   agent_summaries: array
     * }
     */
    public function generateDigest(Store $store, ?string $period = 'daily'): array
    {
        $startDate = $this->getStartDate($period);

        $runs = AgentRun::forStore($store->id)
            ->where('created_at', '>=', $startDate)
            ->with('agent')
            ->get();

        $actions = AgentAction::forStore($store->id)
            ->where('created_at', '>=', $startDate)
            ->get();

        return [
            'period' => $period,
            'start_date' => $startDate->toDateTimeString(),
            'end_date' => now()->toDateTimeString(),
            'runs_total' => $runs->count(),
            'runs_successful' => $runs->where('status', 'completed')->count(),
            'runs_failed' => $runs->where('status', 'failed')->count(),
            'actions_executed' => $actions->where('status', 'executed')->count(),
            'actions_pending' => $actions->where('status', 'pending')->count(),
            'actions_rejected' => $actions->where('status', 'rejected')->count(),
            'highlights' => $this->generateHighlights($store, $startDate, $actions),
            'agent_summaries' => $this->generateAgentSummaries($runs),
        ];
    }

    /**
     * Send digest email to store owner.
     */
    public function sendDigest(Store $store, string $period = 'daily'): bool
    {
        $digest = $this->generateDigest($store, $period);

        if ($this->shouldSkipDigest($digest)) {
            return false;
        }

        $owner = $store->owner;

        if (! $owner?->email) {
            return false;
        }

        // Send the digest email
        Mail::send('emails.agent-digest', [
            'store' => $store,
            'digest' => $digest,
        ], function ($message) use ($owner, $store) {
            $message->to($owner->email)
                ->subject("Daily Agent Digest - {$store->name}");
        });

        return true;
    }

    /**
     * Check if digest should be skipped (no activity).
     */
    protected function shouldSkipDigest(array $digest): bool
    {
        return $digest['runs_total'] === 0
            && $digest['actions_pending'] === 0;
    }

    protected function getStartDate(string $period): \Carbon\Carbon
    {
        return match ($period) {
            'hourly' => now()->subHour(),
            'daily' => now()->subDay(),
            'weekly' => now()->subWeek(),
            'monthly' => now()->subMonth(),
            default => now()->subDay(),
        };
    }

    protected function generateHighlights(Store $store, \Carbon\Carbon $startDate, Collection $actions): array
    {
        $highlights = [];

        // Price changes
        $priceActions = $actions->where('action_type', 'price_update')
            ->where('status', 'executed');

        if ($priceActions->count() > 0) {
            $biggestChange = $priceActions->sortByDesc(function ($action) {
                $before = $action->payload['before']['price'] ?? 0;
                $after = $action->payload['after']['price'] ?? 0;

                return abs($after - $before);
            })->first();

            if ($biggestChange) {
                $highlights[] = [
                    'type' => 'price_update',
                    'message' => "Biggest price change: \${$biggestChange->payload['before']['price']} -> \${$biggestChange->payload['after']['price']}",
                    'action_id' => $biggestChange->id,
                ];
            }
        }

        // Notifications sent
        $notificationActions = $actions->where('action_type', 'send_notification')
            ->where('status', 'executed');

        if ($notificationActions->count() > 0) {
            $highlights[] = [
                'type' => 'notifications',
                'message' => "{$notificationActions->count()} customer notifications sent",
            ];
        }

        // Markdown schedules
        $markdownActions = $actions->where('action_type', 'markdown_schedule')
            ->where('status', 'executed');

        if ($markdownActions->count() > 0) {
            $highlights[] = [
                'type' => 'markdowns',
                'message' => "{$markdownActions->count()} products marked down",
            ];
        }

        return $highlights;
    }

    protected function generateAgentSummaries(Collection $runs): array
    {
        $summaries = [];

        $byAgent = $runs->groupBy('agent_id');

        foreach ($byAgent as $agentId => $agentRuns) {
            $agent = $agentRuns->first()?->agent;

            if (! $agent) {
                continue;
            }

            $summaries[] = [
                'agent_name' => $agent->name,
                'agent_slug' => $agent->slug,
                'total_runs' => $agentRuns->count(),
                'successful' => $agentRuns->where('status', 'completed')->count(),
                'failed' => $agentRuns->where('status', 'failed')->count(),
                'last_run' => $agentRuns->sortByDesc('created_at')->first()?->created_at?->toDateTimeString(),
            ];
        }

        return $summaries;
    }
}
