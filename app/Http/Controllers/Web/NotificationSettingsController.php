<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\NotificationSubscription;
use App\Models\NotificationTemplate;
use App\Services\Notifications\NotificationDataPreparer;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationSettingsController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected NotificationDataPreparer $dataPreparer,
    ) {}

    public function index(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();
        if (! $store) {
            return redirect()->route('dashboard')->with('error', 'Please select a store first.');
        }

        $stats = [
            'templates' => NotificationTemplate::where('store_id', $store->id)->count(),
            'subscriptions' => NotificationSubscription::where('store_id', $store->id)->count(),
            'sent_today' => NotificationLog::where('store_id', $store->id)
                ->whereDate('created_at', today())->count(),
            'sent_week' => NotificationLog::where('store_id', $store->id)
                ->where('created_at', '>=', now()->subWeek())->count(),
        ];

        $recentLogs = NotificationLog::where('store_id', $store->id)
            ->with(['template:id,name,channel', 'subscription:id,activity,name'])
            ->latest()
            ->limit(10)
            ->get();

        return Inertia::render('settings/notifications/Index', [
            'stats' => $stats,
            'recentLogs' => $recentLogs,
            'channelTypes' => NotificationChannel::TYPES,
        ]);
    }

    public function templates(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();
        if (! $store) {
            return redirect()->route('dashboard')->with('error', 'Please select a store first.');
        }

        $templates = NotificationTemplate::where('store_id', $store->id)
            ->withCount('subscriptions')
            ->orderBy('name')
            ->get();

        return Inertia::render('settings/notifications/Templates', [
            'templates' => $templates,
            'channelTypes' => NotificationChannel::TYPES,
            'categories' => ['orders', 'products', 'inventory', 'customers', 'team'],
        ]);
    }

    public function createTemplate(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();
        if (! $store) {
            return redirect()->route('dashboard')->with('error', 'Please select a store first.');
        }

        return Inertia::render('settings/notifications/TemplateEditor', [
            'template' => null,
            'channelTypes' => NotificationChannel::TYPES,
            'categories' => ['orders', 'products', 'inventory', 'customers', 'team'],
            'defaultTemplates' => NotificationTemplate::getDefaultTemplates(),
            'sampleData' => $this->dataPreparer->getSampleData(),
            'availableVariables' => $this->dataPreparer->getAvailableVariables(),
        ]);
    }

    public function editTemplate(NotificationTemplate $template): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();
        if (! $store || $template->store_id !== $store->id) {
            abort(404);
        }

        return Inertia::render('settings/notifications/TemplateEditor', [
            'template' => $template,
            'channelTypes' => NotificationChannel::TYPES,
            'categories' => ['orders', 'products', 'inventory', 'customers', 'team'],
            'defaultTemplates' => NotificationTemplate::getDefaultTemplates(),
            'sampleData' => $this->dataPreparer->getSampleData(),
            'availableVariables' => $this->dataPreparer->getAvailableVariables(),
        ]);
    }

    public function subscriptions(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();
        if (! $store) {
            return redirect()->route('dashboard')->with('error', 'Please select a store first.');
        }

        $subscriptions = NotificationSubscription::where('store_id', $store->id)
            ->with('template:id,name,channel,category')
            ->orderBy('activity')
            ->get();

        $templates = NotificationTemplate::where('store_id', $store->id)
            ->where('is_enabled', true)
            ->orderBy('name')
            ->get(['id', 'name', 'channel', 'category']);

        $activities = Activity::getDefinitions();
        $groupedActivities = collect($activities)->groupBy('category');

        return Inertia::render('settings/notifications/Subscriptions', [
            'subscriptions' => $subscriptions,
            'templates' => $templates,
            'activities' => $activities,
            'groupedActivities' => $groupedActivities,
            'scheduleTypes' => ['immediate', 'delayed', 'scheduled'],
            'recipientTypes' => ['owner', 'customer', 'staff', 'custom'],
        ]);
    }

    public function channels(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();
        if (! $store) {
            return redirect()->route('dashboard')->with('error', 'Please select a store first.');
        }

        $channels = NotificationChannel::where('store_id', $store->id)->get();

        return Inertia::render('settings/notifications/Channels', [
            'channels' => $channels,
            'channelTypes' => NotificationChannel::TYPES,
        ]);
    }

    public function saveChannel(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();
        if (! $store) {
            return redirect()->route('dashboard')->with('error', 'Please select a store first.');
        }

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', NotificationChannel::TYPES)],
            'settings' => ['required', 'array'],
        ]);

        $channel = NotificationChannel::updateOrCreate(
            [
                'store_id' => $store->id,
                'type' => $validated['type'],
            ],
            [
                'name' => ucfirst($validated['type']),
                'settings' => $validated['settings'],
                'is_enabled' => true,
            ]
        );

        return redirect()->back()->with('success', ucfirst($validated['type']).' channel configured successfully.');
    }

    public function toggleChannel(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();
        if (! $store) {
            return redirect()->route('dashboard')->with('error', 'Please select a store first.');
        }

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', NotificationChannel::TYPES)],
            'is_enabled' => ['required', 'boolean'],
        ]);

        NotificationChannel::where('store_id', $store->id)
            ->where('type', $validated['type'])
            ->update(['is_enabled' => $validated['is_enabled']]);

        return redirect()->back();
    }

    public function logs(Request $request): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();
        if (! $store) {
            return redirect()->route('dashboard')->with('error', 'Please select a store first.');
        }

        $query = NotificationLog::where('store_id', $store->id)
            ->with(['template:id,name,channel', 'subscription:id,activity,name']);

        if ($search = $request->get('search')) {
            $query->where('recipient', 'like', "%{$search}%");
        }

        if ($channel = $request->get('channel')) {
            $query->where('channel', $channel);
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($dateFrom = $request->get('date_from')) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->get('date_to')) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $logs = $query->latest()->paginate(20);

        return Inertia::render('settings/notifications/Logs', [
            'logs' => Inertia::defer(fn () => $logs->items()),
            'channelTypes' => NotificationChannel::TYPES,
            'statusTypes' => [
                NotificationLog::STATUS_PENDING,
                NotificationLog::STATUS_SENT,
                NotificationLog::STATUS_DELIVERED,
                NotificationLog::STATUS_FAILED,
                NotificationLog::STATUS_BOUNCED,
            ],
            'filters' => [
                'search' => $request->get('search'),
                'channel' => $request->get('channel'),
                'status' => $request->get('status'),
                'date_from' => $request->get('date_from'),
                'date_to' => $request->get('date_to'),
            ],
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }
}
