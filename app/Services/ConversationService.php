<?php

namespace App\Services;

use App\Enums\ConversationChannel;
use App\Events\ConversationStatusChanged;
use App\Events\NewChatMessage;
use App\Models\StorefrontChatMessage;
use App\Models\StorefrontChatSession;
use App\Models\User;
use App\Services\Channels\SlackService;
use App\Services\Channels\WhatsAppService;

class ConversationService
{
    public function assign(StorefrontChatSession $session, User $agent): void
    {
        $session->assign($agent);

        event(new ConversationStatusChanged($session));
    }

    public function release(StorefrontChatSession $session): void
    {
        $session->release();

        event(new ConversationStatusChanged($session));
    }

    public function close(StorefrontChatSession $session): void
    {
        $session->close();

        event(new ConversationStatusChanged($session));
    }

    public function sendAgentMessage(StorefrontChatSession $session, User $agent, string $content): StorefrontChatMessage
    {
        $message = StorefrontChatMessage::create([
            'storefront_chat_session_id' => $session->id,
            'role' => 'agent',
            'agent_id' => $agent->id,
            'content' => $content,
        ]);

        $session->touchLastMessage();

        event(new NewChatMessage($message));

        $this->deliverToChannel($session, $content);

        return $message;
    }

    /**
     * Route message delivery based on session channel.
     */
    protected function deliverToChannel(StorefrontChatSession $session, string $content): void
    {
        if (! $session->external_thread_id) {
            return;
        }

        $config = $session->store->channelConfigurations()
            ->where('channel', $session->channel)
            ->where('is_active', true)
            ->first();

        if (! $config) {
            return;
        }

        match ($session->channel) {
            ConversationChannel::WhatsApp => app(WhatsAppService::class)->sendMessage(
                $config->credentials,
                $session->external_thread_id,
                $content,
            ),
            ConversationChannel::Slack => app(SlackService::class)->sendMessage(
                $config->credentials,
                $session->external_thread_id,
                $content,
            ),
            default => null,
        };
    }
}
