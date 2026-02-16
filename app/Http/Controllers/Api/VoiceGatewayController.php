<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VoiceCommitment;
use App\Models\VoiceMemory;
use App\Models\VoiceSession;
use App\Services\Voice\VoiceGatewayAuth;
use App\Services\Voice\VoiceMemoryService;
use App\Services\Voice\VoiceToolAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class VoiceGatewayController extends Controller
{
    public function __construct(
        protected VoiceGatewayAuth $gatewayAuth,
        protected VoiceMemoryService $memoryService,
        protected VoiceToolAdapter $toolAdapter
    ) {}

    /**
     * Create a new voice session.
     */
    public function createSession(Request $request): JsonResponse
    {
        $request->validate([
            'gateway_session_id' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $storeId = $user->current_store_id;

        $session = VoiceSession::create([
            'id' => Str::uuid(),
            'store_id' => $storeId,
            'user_id' => $user->id,
            'gateway_session_id' => $request->input('gateway_session_id'),
            'status' => 'active',
            'started_at' => now(),
        ]);

        // Generate a session token for WebRTC signaling
        $sessionToken = $this->gatewayAuth->generateSessionToken(
            $session->id,
            $user->id,
            $storeId
        );

        return response()->json([
            'success' => true,
            'session' => [
                'id' => $session->id,
                'status' => $session->status,
                'started_at' => $session->started_at->toIso8601String(),
            ],
            'token' => $sessionToken,
            'gateway_url' => config('services.voice_gateway.url', 'http://localhost:3001'),
        ]);
    }

    /**
     * Get session details.
     */
    public function getSession(VoiceSession $session): JsonResponse
    {
        return response()->json([
            'success' => true,
            'session' => [
                'id' => $session->id,
                'status' => $session->status,
                'started_at' => $session->started_at?->toIso8601String(),
                'ended_at' => $session->ended_at?->toIso8601String(),
                'duration_seconds' => $session->total_duration_seconds,
            ],
        ]);
    }

    /**
     * End a voice session.
     */
    public function endSession(VoiceSession $session): JsonResponse
    {
        $session->end();

        return response()->json([
            'success' => true,
            'session' => [
                'id' => $session->id,
                'status' => $session->status,
                'ended_at' => $session->ended_at->toIso8601String(),
                'duration_seconds' => $session->total_duration_seconds,
            ],
        ]);
    }

    /**
     * Execute a tool (called by Node.js gateway).
     */
    public function executeTool(Request $request): JsonResponse
    {
        $request->validate([
            'tool_name' => ['required', 'string'],
            'params' => ['nullable', 'array'],
        ]);

        $user = $request->user();
        $storeId = $user->current_store_id;
        $toolName = $request->input('tool_name');
        $params = $request->input('params', []);

        if (! $this->toolAdapter->has($toolName)) {
            return response()->json([
                'success' => false,
                'error' => "Unknown tool: {$toolName}",
            ], 400);
        }

        $result = $this->toolAdapter->execute($toolName, $params, $storeId, $user->id);

        return response()->json([
            'success' => true,
            'result' => $result,
        ]);
    }

    /**
     * Get memories for the current store.
     */
    public function getMemories(Request $request): JsonResponse
    {
        $request->validate([
            'category' => ['nullable', 'string'],
            'type' => ['nullable', 'string', 'in:fact,preference,commitment,context'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $storeId = $user->current_store_id;
        $category = $request->input('category');
        $limit = $request->input('limit', 20);

        $query = VoiceMemory::forStore($storeId)->active();

        if ($category) {
            $query->inCategory($category);
        }

        if ($request->input('type')) {
            $query->ofType($request->input('type'));
        }

        $memories = $query->orderByDesc('confidence')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'count' => $memories->count(),
            'memories' => $memories->map(fn (VoiceMemory $m) => [
                'id' => $m->id,
                'content' => $m->content,
                'type' => $m->memory_type,
                'category' => $m->category,
                'confidence' => $m->confidence,
                'created_at' => $m->created_at->toIso8601String(),
            ])->toArray(),
        ]);
    }

    /**
     * Store a new memory.
     */
    public function storeMemory(Request $request): JsonResponse
    {
        $request->validate([
            'content' => ['required', 'string', 'min:5', 'max:1000'],
            'type' => ['nullable', 'string', 'in:fact,preference,commitment,context'],
            'category' => ['nullable', 'string', 'max:100'],
            'confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'source_id' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $storeId = $user->current_store_id;

        $memory = $this->memoryService->remember(
            storeId: $storeId,
            content: $request->input('content'),
            type: $request->input('type', 'fact'),
            category: $request->input('category'),
            confidence: $request->input('confidence', 1.0),
            sourceId: $request->input('source_id')
        );

        return response()->json([
            'success' => true,
            'memory' => [
                'id' => $memory->id,
                'content' => $memory->content,
                'type' => $memory->memory_type,
                'category' => $memory->category,
            ],
        ], 201);
    }

    /**
     * Search memories.
     */
    public function searchMemories(Request $request): JsonResponse
    {
        $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $user = $request->user();
        $storeId = $user->current_store_id;

        $memories = $this->memoryService->search(
            $storeId,
            $request->input('query'),
            $request->input('limit', 5)
        );

        return response()->json([
            'success' => true,
            'count' => $memories->count(),
            'memories' => $memories->map(fn (VoiceMemory $m) => [
                'id' => $m->id,
                'content' => $m->content,
                'type' => $m->memory_type,
                'category' => $m->category,
                'confidence' => $m->confidence,
            ])->toArray(),
        ]);
    }

    /**
     * Get commitments for the current user.
     */
    public function getCommitments(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'string', 'in:pending,completed,cancelled,overdue'],
            'type' => ['nullable', 'string', 'in:follow_up,reminder,action,promise'],
            'include_completed' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $storeId = $user->current_store_id;

        $query = VoiceCommitment::forStore($storeId)->forUser($user->id);

        if ($request->input('status')) {
            $query->where('status', $request->input('status'));
        } elseif (! $request->boolean('include_completed')) {
            $query->pending();
        }

        if ($request->input('type')) {
            $query->ofType($request->input('type'));
        }

        $commitments = $query->orderBy('due_at')->orderByDesc('created_at')->get();

        return response()->json([
            'success' => true,
            'count' => $commitments->count(),
            'commitments' => $commitments->map(fn (VoiceCommitment $c) => [
                'id' => $c->id,
                'type' => $c->commitment_type,
                'description' => $c->description,
                'status' => $c->status,
                'due_at' => $c->due_at?->toIso8601String(),
                'due_formatted' => $c->due_at?->diffForHumans(),
                'is_overdue' => $c->isOverdue(),
                'created_at' => $c->created_at->toIso8601String(),
            ])->toArray(),
        ]);
    }

    /**
     * Create a new commitment.
     */
    public function createCommitment(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['required', 'string', 'in:follow_up,reminder,action,promise'],
            'description' => ['required', 'string', 'min:5', 'max:1000'],
            'due_at' => ['nullable', 'date'],
            'session_id' => ['nullable', 'uuid'],
            'related_entity_type' => ['nullable', 'string', 'max:100'],
            'related_entity_id' => ['nullable', 'integer'],
            'metadata' => ['nullable', 'array'],
        ]);

        $user = $request->user();
        $storeId = $user->current_store_id;

        $commitment = $this->memoryService->createCommitment(
            storeId: $storeId,
            userId: $user->id,
            type: $request->input('type'),
            description: $request->input('description'),
            dueAt: $request->input('due_at') ? new \DateTime($request->input('due_at')) : null,
            sessionId: $request->input('session_id'),
            entityType: $request->input('related_entity_type'),
            entityId: $request->input('related_entity_id'),
            metadata: $request->input('metadata')
        );

        return response()->json([
            'success' => true,
            'commitment' => [
                'id' => $commitment->id,
                'type' => $commitment->commitment_type,
                'description' => $commitment->description,
                'status' => $commitment->status,
                'due_at' => $commitment->due_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Update a commitment.
     */
    public function updateCommitment(Request $request, VoiceCommitment $commitment): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'string', 'in:pending,completed,cancelled'],
            'due_at' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'min:5', 'max:1000'],
        ]);

        if ($request->input('status') === 'completed') {
            $commitment->markCompleted();
        } elseif ($request->input('status') === 'cancelled') {
            $commitment->markCancelled();
        }

        if ($request->has('due_at')) {
            $commitment->snooze(new \DateTime($request->input('due_at')));
        }

        if ($request->has('description')) {
            $commitment->update(['description' => $request->input('description')]);
        }

        return response()->json([
            'success' => true,
            'commitment' => [
                'id' => $commitment->id,
                'type' => $commitment->commitment_type,
                'description' => $commitment->description,
                'status' => $commitment->status,
                'due_at' => $commitment->due_at?->toIso8601String(),
                'completed_at' => $commitment->completed_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Generate a gateway token for the current user.
     */
    public function generateGatewayToken(Request $request): JsonResponse
    {
        $user = $request->user();
        $storeId = $user->current_store_id;

        $token = $this->gatewayAuth->generateToken($user, $storeId);

        return response()->json([
            'success' => true,
            'token' => $token,
            'expires_in' => 3600, // 1 hour
            'gateway_url' => config('services.voice_gateway.url', 'http://localhost:3001'),
        ]);
    }

    /**
     * Get context memories for injection into conversation.
     */
    public function getContextMemories(Request $request): JsonResponse
    {
        $request->validate([
            'query' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();
        $storeId = $user->current_store_id;

        $context = $this->memoryService->getContextMemories(
            $storeId,
            $request->input('query')
        );

        return response()->json([
            'success' => true,
            'context' => $context,
        ]);
    }
}
