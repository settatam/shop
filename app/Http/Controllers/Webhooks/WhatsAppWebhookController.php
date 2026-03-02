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
use App\Services\Channels\WhatsAppService;
use App\Services\StorefrontChat\StorefrontChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WhatsAppWebhookController extends Controller
{
    public function __construct(
        protected WhatsAppService $whatsAppService,
        protected StorefrontChatService $chatService,
    ) {}

    /**
     * Handle Meta webhook verification challenge.
     */
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token === config('services.whatsapp.verify_token')) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /**
     * Handle incoming WhatsApp messages.
     */
    public function handle(Request $request): JsonResponse
    {
        $parsed = $this->whatsAppService->parseIncomingMessage($request->all());

        if (! $parsed) {
            return response()->json(['status' => 'ignored']);
        }

        $phoneNumber = $parsed['phone'];
        $messageText = $parsed['message'];

        // Find a channel config that matches (lookup by phone number in external_thread_id)
        $session = StorefrontChatSession::where('channel', ConversationChannel::WhatsApp)
            ->where('external_thread_id', $phoneNumber)
            ->whereNot('status', ConversationStatus::Closed)
            ->first();

        if (! $session) {
            // Find any active WhatsApp channel config to determine the store
            $config = ChannelConfiguration::where('channel', ConversationChannel::WhatsApp)
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
                'visitor_id' => 'whatsapp_'.$phoneNumber,
                'channel' => ConversationChannel::WhatsApp,
                'external_thread_id' => $phoneNumber,
            ]);

            event(new NewConversation($session));
        }

        // Save the incoming message
        $message = StorefrontChatMessage::create([
            'storefront_chat_session_id' => $session->id,
            'role' => 'user',
            'content' => $messageText,
        ]);

        $session->touchLastMessage();
        $session->generateTitle();

        event(new NewChatMessage($message));

        // If assigned to an agent, just notify — don't run AI
        if ($session->isAssigned()) {
            return response()->json(['status' => 'delivered_to_agent']);
        }

        // Run AI and send response back via WhatsApp
        $store = $session->store;
        $config = ChannelConfiguration::where('store_id', $store->id)
            ->where('channel', ConversationChannel::WhatsApp)
            ->where('is_active', true)
            ->first();

        $aiResponse = '';
        foreach ($this->chatService->streamMessage($session, $messageText, $store) as $event) {
            if ($event['type'] === 'token') {
                $aiResponse .= $event['content'];
            }
        }

        if ($aiResponse && $config) {
            $this->whatsAppService->sendMessage(
                $config->credentials,
                $phoneNumber,
                $aiResponse,
            );
        }

        return response()->json(['status' => 'processed']);
    }
}
