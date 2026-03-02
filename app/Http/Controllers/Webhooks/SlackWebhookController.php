<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use App\Events\NewChatMessage;
use App\Events\NewConversation;
use App\Http\Controllers\Controller;
use App\Models\ChannelConfiguration;
use App\Models\StorefrontChatMessage;
use App\Models\StorefrontChatSession;
use App\Models\StoreMarketplace;
use App\Services\Channels\SlackService;
use App\Services\StorefrontChat\StorefrontChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SlackWebhookController extends Controller
{
    public function __construct(
        protected SlackService $slackService,
        protected StorefrontChatService $chatService,
    ) {}

    /**
     * Handle Slack Events API requests.
     */
    public function handle(Request $request): JsonResponse
    {
        // URL verification challenge
        if ($request->input('type') === 'url_verification') {
            return response()->json(['challenge' => $request->input('challenge')]);
        }

        $parsed = $this->slackService->parseIncomingMessage($request->all());

        if (! $parsed) {
            return response()->json(['status' => 'ignored']);
        }

        $threadId = $parsed['channel'].':'.$parsed['thread_ts'];
        $messageText = $parsed['text'];

        $session = StorefrontChatSession::where('channel', ConversationChannel::Slack)
            ->where('external_thread_id', $threadId)
            ->whereNot('status', ConversationStatus::Closed)
            ->first();

        if (! $session) {
            $config = ChannelConfiguration::where('channel', ConversationChannel::Slack)
                ->where('is_active', true)
                ->first();

            if (! $config) {
                return response()->json(['status' => 'no_config']);
            }

            $store = $config->store;
            $marketplace = StoreMarketplace::where('store_id', $store->id)->where('status', 'active')->first();

            if (! $marketplace) {
                return response()->json(['status' => 'no_marketplace']);
            }

            $session = StorefrontChatSession::create([
                'store_id' => $store->id,
                'store_marketplace_id' => $marketplace->id,
                'visitor_id' => 'slack_'.$parsed['user'],
                'channel' => ConversationChannel::Slack,
                'external_thread_id' => $threadId,
            ]);

            event(new NewConversation($session));
        }

        $message = StorefrontChatMessage::create([
            'storefront_chat_session_id' => $session->id,
            'role' => 'user',
            'content' => $messageText,
        ]);

        $session->touchLastMessage();
        $session->generateTitle();

        event(new NewChatMessage($message));

        if ($session->isAssigned()) {
            return response()->json(['status' => 'delivered_to_agent']);
        }

        // Run AI and respond in Slack thread
        $store = $session->store;
        $config = ChannelConfiguration::where('store_id', $store->id)
            ->where('channel', ConversationChannel::Slack)
            ->where('is_active', true)
            ->first();

        $aiResponse = '';
        foreach ($this->chatService->streamMessage($session, $messageText, $store) as $event) {
            if ($event['type'] === 'token') {
                $aiResponse .= $event['content'];
            }
        }

        if ($aiResponse && $config) {
            $this->slackService->sendMessage(
                $config->credentials,
                $threadId,
                $aiResponse,
            );
        }

        return response()->json(['status' => 'processed']);
    }
}
