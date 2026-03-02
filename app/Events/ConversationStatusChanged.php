<?php

namespace App\Events;

use App\Models\StorefrontChatSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public StorefrontChatSession $session) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('store.'.$this->session->store_id.'.conversations'),
            new PrivateChannel('conversation.'.$this->session->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->session->id,
            'status' => $this->session->status->value,
            'assigned_agent_id' => $this->session->assigned_agent_id,
            'assigned_agent_name' => $this->session->assignedAgent?->name,
            'closed_at' => $this->session->closed_at?->toISOString(),
        ];
    }
}
