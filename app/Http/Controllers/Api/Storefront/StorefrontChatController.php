<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StorefrontApiToken;
use App\Models\StoreKnowledgeBaseEntry;
use App\Models\StoreMarketplace;
use App\Services\StorefrontChat\StorefrontChatService;
use App\Services\StorefrontChat\StorefrontChatToolExecutor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StorefrontChatController extends Controller
{
    public function __construct(
        protected StorefrontChatService $chatService,
        protected StorefrontChatToolExecutor $toolExecutor
    ) {}

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
     * Return store configuration for the voice gateway.
     */
    public function voiceConfig(Request $request): JsonResponse
    {
        $request->validate([
            'shop' => ['required', 'string'],
        ]);

        $internalKey = $request->header('X-Internal-Key');

        if (! $internalKey || $internalKey !== config('services.voice_gateway.internal_key')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $marketplace = StoreMarketplace::where('shop_domain', $request->query('shop'))
            ->where('is_active', true)
            ->first();

        if (! $marketplace) {
            return response()->json(['error' => 'Store not found'], 404);
        }

        $store = $marketplace->store;
        $token = StorefrontApiToken::where('store_marketplace_id', $marketplace->id)
            ->where('is_active', true)
            ->first();

        $settings = $token?->settings ?? [];
        $knowledgeBase = $this->buildKnowledgeBaseContext($store->id);

        return response()->json([
            'store_id' => $store->id,
            'store_name' => $store->name,
            'assistant_name' => $settings['assistant_name'] ?? $store->name.' Assistant',
            'welcome_message' => $settings['welcome_message'] ?? 'Hi! How can I help you find the perfect piece today?',
            'knowledge_base' => $knowledgeBase,
        ]);
    }

    /**
     * Execute a storefront chat tool on behalf of the voice gateway.
     */
    public function executeTool(Request $request, string $tool): JsonResponse
    {
        $internalKey = $request->header('X-Internal-Key');

        if (! $internalKey || $internalKey !== config('services.voice_gateway.internal_key')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $request->validate([
            'store_id' => ['required', 'integer'],
        ]);

        $storeId = $request->input('store_id');

        if (! Store::where('id', $storeId)->exists()) {
            return response()->json(['error' => 'Store not found'], 404);
        }

        $params = $request->except(['store_id']);
        $result = $this->toolExecutor->execute($tool, $params, $storeId);

        return response()->json($result);
    }

    /**
     * Build knowledge base context string for a store.
     */
    protected function buildKnowledgeBaseContext(int $storeId): string
    {
        $entries = StoreKnowledgeBaseEntry::where('store_id', $storeId)
            ->where('is_active', true)
            ->orderBy('type')
            ->orderBy('sort_order')
            ->get();

        if ($entries->isEmpty()) {
            return '';
        }

        $sections = [];
        $grouped = $entries->groupBy('type');

        foreach ($grouped as $type => $group) {
            $label = match ($type) {
                'return_policy' => 'Return Policy',
                'shipping_info' => 'Shipping Information',
                'care_instructions' => 'Jewelry Care Instructions',
                'faq' => 'Frequently Asked Questions',
                'about' => 'About the Store',
                default => ucfirst(str_replace('_', ' ', $type)),
            };

            $content = $group->map(fn ($entry) => "**{$entry->title}**: {$entry->content}")->implode("\n");
            $sections[] = "[{$label}]\n{$content}";
        }

        return implode("\n\n", $sections);
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
