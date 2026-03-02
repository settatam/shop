<?php

namespace App\Events;

use App\Models\StorefrontChatSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewConversation implements ShouldBroadcast
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $firstMessage = $this->session->messages()->where('role', 'user')->first();

        return [
            'id' => $this->session->id,
            'visitor_id' => $this->session->visitor_id,
            'channel' => $this->session->channel->value,
            'status' => $this->session->status->value,
            'title' => $this->session->title,
            'first_message' => $firstMessage?->content,
            'created_at' => $this->session->created_at->toISOString(),
        ];
    }
}
