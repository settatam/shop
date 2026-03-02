<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendAgentMessageRequest;
use App\Models\ChannelConfiguration;
use App\Models\StorefrontChatSession;
use App\Services\Channels\ZoomService;
use App\Services\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationApiController extends Controller
{
    public function __construct(protected ConversationService $conversationService) {}

    public function assign(Request $request, StorefrontChatSession $session): JsonResponse
    {
        $this->conversationService->assign($session, $request->user());

        return response()->json(['status' => 'assigned']);
    }

    public function release(StorefrontChatSession $session): JsonResponse
    {
        $this->conversationService->release($session);

        return response()->json(['status' => 'released']);
    }

    public function close(StorefrontChatSession $session): JsonResponse
    {
        $this->conversationService->close($session);

        return response()->json(['status' => 'closed']);
    }

    public function sendMessage(SendAgentMessageRequest $request, StorefrontChatSession $session): JsonResponse
    {
        $message = $this->conversationService->sendAgentMessage(
            $session,
            $request->user(),
            $request->validated('content'),
        );

        return response()->json([
            'message' => [
                'id' => $message->id,
                'role' => $message->role,
                'content' => $message->content,
                'agent_id' => $message->agent_id,
                'created_at' => $message->created_at->toISOString(),
            ],
        ], 201);
    }

    public function messages(StorefrontChatSession $session): JsonResponse
    {
        $messages = $session->messages()
            ->with('agent')
            ->orderBy('created_at')
            ->paginate(50);

        return response()->json($messages);
    }

    public function escalateToZoom(Request $request, StorefrontChatSession $session): JsonResponse
    {
        $config = ChannelConfiguration::where('store_id', $session->store_id)
            ->where('channel', 'zoom')
            ->where('is_active', true)
            ->first();

        if (! $config) {
            return response()->json(['error' => 'Zoom is not configured for this store.'], 422);
        }

        $zoomService = app(ZoomService::class);
        $meeting = $zoomService->createMeeting(
            $config->credentials,
            'Support Call - '.$session->title,
        );

        if (! $meeting) {
            return response()->json(['error' => 'Failed to create Zoom meeting.'], 500);
        }

        // Send the meeting link as an agent message
        $this->conversationService->sendAgentMessage(
            $session,
            $request->user(),
            "I've set up a video call for us. Please join here: {$meeting['join_url']}",
        );

        return response()->json([
            'join_url' => $meeting['join_url'],
            'meeting_id' => $meeting['meeting_id'],
        ]);
    }
}
