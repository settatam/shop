<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Controller;
use App\Models\StoreMarketplace;
use App\Services\StorefrontChat\StorefrontChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StorefrontChatController extends Controller
{
    public function __construct(protected StorefrontChatService $chatService) {}

    /**
     * Stream a chat response via SSE.
     */
    public function chat(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'visitor_id' => ['required', 'string', 'max:64'],
            'session_id' => ['nullable', 'uuid'],
        ]);

        /** @var StoreMarketplace $marketplace */
        $marketplace = $request->attributes->get('marketplace');
        $store = $marketplace->store;

        $session = $this->chatService->getOrCreateSession(
            $validated['session_id'] ?? null,
            $store->id,
            $marketplace->id,
            $validated['visitor_id'],
        );

        return response()->stream(function () use ($session, $validated, $store) {
            if (ob_get_level()) {
                ob_end_clean();
            }

            foreach ($this->chatService->streamMessage($session, $validated['message'], $store) as $event) {
                $this->sendSSE($event['type'], $event);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Create or restore a chat session.
     */
    public function session(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'visitor_id' => ['required', 'string', 'max:64'],
            'session_id' => ['nullable', 'uuid'],
        ]);

        /** @var StoreMarketplace $marketplace */
        $marketplace = $request->attributes->get('marketplace');
        $store = $marketplace->store;

        $session = $this->chatService->getOrCreateSession(
            $validated['session_id'] ?? null,
            $store->id,
            $marketplace->id,
            $validated['visitor_id'],
        );

        $token = $request->attributes->get('storefront_token');
        $settings = $token?->settings ?? [];

        return response()->json([
            'session_id' => $session->id,
            'expires_at' => $session->expires_at?->toISOString(),
            'config' => [
                'welcome_message' => $settings['welcome_message'] ?? 'Hi! How can I help you find the perfect piece today?',
                'assistant_name' => $settings['assistant_name'] ?? $store->name.' Assistant',
                'accent_color' => $settings['accent_color'] ?? '#1a1a2e',
            ],
        ]);
    }

    /**
     * Send an SSE event to the client.
     *
     * @param  array<string, mixed>  $data
     */
    protected function sendSSE(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: '.json_encode($data)."\n\n";

        if (connection_aborted()) {
            exit;
        }

        flush();
    }
}
