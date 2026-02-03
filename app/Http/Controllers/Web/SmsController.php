<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SmsController extends Controller
{
    /**
     * Display the SMS message center.
     */
    public function index(Request $request): Response
    {
        $store = $request->user()->store;

        $query = NotificationLog::where('store_id', $store->id)
            ->where('channel', NotificationChannel::TYPE_SMS)
            ->with(['notifiable', 'recipientModel']);

        // Filter by direction
        if ($request->filled('direction')) {
            $query->where('direction', $request->direction);
        }

        // Filter by read status
        if ($request->filled('read_status')) {
            if ($request->read_status === 'unread') {
                $query->whereNull('read_at');
            } elseif ($request->read_status === 'read') {
                $query->whereNotNull('read_at');
            }
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Search by phone number or content
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('content', 'like', "%{$search}%")
                    ->orWhere('recipient', 'like', "%{$search}%")
                    ->orWhereHas('recipientModel', function ($q) use ($search) {
                        $q->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('phone_number', 'like', "%{$search}%");
                    });
            });
        }

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $messages = $query->orderBy('created_at', 'desc')
            ->paginate(25)
            ->through(fn ($log) => $this->formatMessage($log));

        // Get SMS templates for quick replies
        $templates = NotificationTemplate::where('store_id', $store->id)
            ->where('channel', NotificationChannel::TYPE_SMS)
            ->where('is_enabled', true)
            ->orderBy('name')
            ->get(['id', 'name', 'content', 'category']);

        // Get unread count
        $unreadCount = NotificationLog::where('store_id', $store->id)
            ->where('channel', NotificationChannel::TYPE_SMS)
            ->where('direction', NotificationLog::DIRECTION_INBOUND)
            ->whereNull('read_at')
            ->count();

        return Inertia::render('sms/Index', [
            'messages' => $messages,
            'templates' => $templates,
            'unreadCount' => $unreadCount,
            'filters' => $request->only(['direction', 'read_status', 'status', 'search', 'date_from', 'date_to']),
        ]);
    }

    /**
     * Show a specific conversation thread.
     */
    public function show(Request $request, int $id): Response
    {
        $store = $request->user()->store;

        $message = NotificationLog::where('store_id', $store->id)
            ->where('channel', NotificationChannel::TYPE_SMS)
            ->findOrFail($id);

        // Mark as read if inbound
        if ($message->direction === NotificationLog::DIRECTION_INBOUND && ! $message->read_at) {
            $message->markAsRead();
        }

        // Get all messages for this conversation (same customer/transaction)
        $conversationQuery = NotificationLog::where('store_id', $store->id)
            ->where('channel', NotificationChannel::TYPE_SMS);

        if ($message->notifiable_type === Transaction::class && $message->notifiable_id) {
            $conversationQuery->where('notifiable_type', Transaction::class)
                ->where('notifiable_id', $message->notifiable_id);
        } elseif ($message->recipient_model_id) {
            $conversationQuery->where('recipient_model_id', $message->recipient_model_id);
        }

        $conversation = $conversationQuery->orderBy('created_at', 'asc')->get();

        // Get SMS templates for quick replies
        $templates = NotificationTemplate::where('store_id', $store->id)
            ->where('channel', NotificationChannel::TYPE_SMS)
            ->where('is_enabled', true)
            ->orderBy('name')
            ->get(['id', 'name', 'content', 'category']);

        return Inertia::render('sms/Show', [
            'message' => $this->formatMessage($message),
            'conversation' => $conversation->map(fn ($log) => $this->formatMessage($log)),
            'templates' => $templates,
        ]);
    }

    /**
     * Mark a message as read.
     */
    public function markAsRead(Request $request, int $id)
    {
        $store = $request->user()->store;

        $message = NotificationLog::where('store_id', $store->id)
            ->where('channel', NotificationChannel::TYPE_SMS)
            ->findOrFail($id);

        $message->markAsRead();

        return back();
    }

    /**
     * Mark multiple messages as read.
     */
    public function markMultipleAsRead(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $store = $request->user()->store;

        NotificationLog::where('store_id', $store->id)
            ->where('channel', NotificationChannel::TYPE_SMS)
            ->whereIn('id', $request->ids)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return back();
    }

    /**
     * Format a notification log for the frontend.
     */
    protected function formatMessage(NotificationLog $log): array
    {
        $customer = $log->recipientModel;
        $transaction = $log->notifiable_type === Transaction::class ? $log->notifiable : null;

        return [
            'id' => $log->id,
            'transaction_id' => $transaction?->id,
            'transaction_number' => $transaction?->transaction_number,
            'customer_id' => $customer?->id,
            'customer_name' => $customer ? trim("{$customer->first_name} {$customer->last_name}") : null,
            'customer_phone' => $customer?->phone_number,
            'direction' => $log->direction,
            'from' => $log->direction === NotificationLog::DIRECTION_INBOUND
                ? ($log->data['from'] ?? $customer?->phone_number)
                : $log->recipient,
            'to' => $log->direction === NotificationLog::DIRECTION_OUTBOUND
                ? $log->recipient
                : ($log->data['to'] ?? null),
            'content' => $log->content,
            'status' => $log->status,
            'is_read' => $log->read_at !== null,
            'read_at' => $log->read_at?->toIso8601String(),
            'sent_at' => $log->sent_at?->toIso8601String(),
            'delivered_at' => $log->delivered_at?->toIso8601String(),
            'created_at' => $log->created_at->toIso8601String(),
        ];
    }
}
