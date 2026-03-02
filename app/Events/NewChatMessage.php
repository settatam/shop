<?php

namespace App\Events;

use App\Models\StorefrontChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewChatMessage implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public StorefrontChatMessage $message) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversation.'.$this->message->storefront_chat_session_id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'session_id' => $this->message->storefront_chat_session_id,
            'role' => $this->message->role,
            'content' => $this->message->content,
            'agent_id' => $this->message->agent_id,
            'agent_name' => $this->message->agent?->name,
            'created_at' => $this->message->created_at->toISOString(),
        ];
    }
}
