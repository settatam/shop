<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\StorefrontChatSession;
use App\Services\StoreContext;
use Inertia\Inertia;
use Inertia\Response;

class ConversationController extends Controller
{
    public function __construct(protected StoreContext $storeContext) {}

    public function index(): Response
    {
        $store = $this->storeContext->getCurrentStore();

        $sessions = StorefrontChatSession::query()
            ->where('store_id', $store->id)
            ->whereNot('status', 'closed')
            ->with(['assignedAgent', 'customer'])
            ->withCount('messages')
            ->latest('last_message_at')
            ->paginate(25);

        return Inertia::render('conversations/Index', [
            'sessions' => $sessions,
        ]);
    }

    public function show(StorefrontChatSession $session): Response
    {
        $store = $this->storeContext->getCurrentStore();

        abort_if($session->store_id !== $store->id, 403);

        $session->load(['assignedAgent', 'customer', 'marketplace']);

        $messages = $session->messages()
            ->with('agent')
            ->orderBy('created_at')
            ->get();

        return Inertia::render('conversations/Show', [
            'session' => $session,
            'messages' => $messages,
        ]);
    }
}
