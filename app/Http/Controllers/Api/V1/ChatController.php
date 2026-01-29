<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ChatSession;
use App\Services\Chat\ChatService;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    public function __construct(
        protected ChatService $chatService,
        protected StoreContext $storeContext,
    ) {}

    /**
     * Send a message and stream the response via SSE.
     */
    public function message(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'session_id' => ['nullable', 'uuid'],
        ]);

        $store = $this->storeContext->getCurrentStore();
        $user = $request->user();

        $session = $this->chatService->getOrCreateSession(
            $validated['session_id'] ?? null,
            $store->id,
            $user->id
        );

        return response()->stream(function () use ($session, $validated, $store) {
            // Disable output buffering for real-time streaming
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
     * Get recent chat sessions for the current user.
     */
    public function sessions(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $user = $request->user();

        $limit = $request->input('limit', 10);

        $sessions = $this->chatService->getRecentSessions(
            $store->id,
            $user->id,
            min($limit, 50)
        );

        return response()->json([
            'data' => $sessions->map(fn (ChatSession $session) => [
                'id' => $session->id,
                'title' => $session->title ?? 'New Chat',
                'last_message_at' => $session->last_message_at?->toISOString(),
                'created_at' => $session->created_at->toISOString(),
            ]),
        ]);
    }

    /**
     * Get a specific chat session with messages.
     */
    public function show(Request $request, ChatSession $session): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $user = $request->user();

        // Verify ownership
        if ($session->store_id !== $store->id || $session->user_id !== $user->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $session->load(['messages' => fn ($q) => $q->orderBy('created_at')]);

        return response()->json([
            'data' => [
                'id' => $session->id,
                'title' => $session->title ?? 'New Chat',
                'last_message_at' => $session->last_message_at?->toISOString(),
                'created_at' => $session->created_at->toISOString(),
                'messages' => $session->messages->map(fn ($msg) => [
                    'id' => $msg->id,
                    'role' => $msg->role,
                    'content' => $msg->content,
                    'created_at' => $msg->created_at->toISOString(),
                ]),
            ],
        ]);
    }

    /**
     * Delete a chat session.
     */
    public function destroy(Request $request, ChatSession $session): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $user = $request->user();

        // Verify ownership
        if ($session->store_id !== $store->id || $session->user_id !== $user->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $session->delete();

        return response()->json(['message' => 'Session deleted successfully.']);
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
