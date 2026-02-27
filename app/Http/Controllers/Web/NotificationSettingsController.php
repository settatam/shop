<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\NotificationChannel;
use App\Models\NotificationLayout;
use App\Models\NotificationLog;
use App\Models\NotificationSubscription;
use App\Models\NotificationTemplate;
use App\Services\Notifications\NotificationDataPreparer;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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

        $layouts = NotificationLayout::where('store_id', $store->id)
            ->where('is_enabled', true)
            ->orderBy('channel')
            ->orderBy('name')
            ->get(['id', 'name', 'channel', 'is_default']);

        return Inertia::render('settings/notifications/TemplateEditor', [
            'template' => null,
            'channelTypes' => NotificationChannel::TYPES,
            'categories' => ['orders', 'products', 'inventory', 'customers', 'team'],
            'defaultTemplates' => NotificationTemplate::getDefaultTemplates(),
            'sampleData' => $this->dataPreparer->getSampleData(),
            'availableVariables' => $this->dataPreparer->getAvailableVariables(),
            'layouts' => $layouts,
        ]);
    }

    public function editTemplate(NotificationTemplate $template): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();
        if (! $store || $template->store_id !== $store->id) {
            abort(404);
        }

        $layouts = NotificationLayout::where('store_id', $store->id)
            ->where('is_enabled', true)
            ->orderBy('channel')
            ->orderBy('name')
            ->get(['id', 'name', 'channel', 'is_default']);

        return Inertia::render('settings/notifications/TemplateEditor', [
            'template' => $template,
            'channelTypes' => NotificationChannel::TYPES,
            'categories' => ['orders', 'products', 'inventory', 'customers', 'team'],
            'defaultTemplates' => NotificationTemplate::getDefaultTemplates(),
            'sampleData' => $this->dataPreparer->getSampleData(),
            'availableVariables' => $this->dataPreparer->getAvailableVariables(),
            'layouts' => $layouts,
        ]);
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();
        if (! $store) {
            return redirect()->route('dashboard')->with('error', 'Please select a store first.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/'],
            'description' => ['nullable', 'string', 'max:500'],
            'channel' => ['required', 'string', Rule::in(NotificationChannel::TYPES)],
            'subject' => ['nullable', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'available_variables' => ['nullable', 'array'],
            'category' => ['nullable', 'string', 'max:50'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $exists = NotificationTemplate::where('store_id', $store->id)
            ->where('slug', $validated['slug'])
            ->where('channel', $validated['channel'])
            ->exists();

        if ($exists) {
            return redirect()->back()->withErrors([
                'slug' => 'A template with this slug already exists for this channel.',
            ])->withInput();
        }

        NotificationTemplate::create([
            ...$validated,
            'store_id' => $store->id,
            'is_enabled' => $validated['is_enabled'] ?? true,
        ]);

        return redirect()->route('settings.notifications.templates')->with('success', 'Template created.');
    }

    public function updateTemplate(Request $request, NotificationTemplate $template): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();
        if (! $store || $template->store_id !== $store->id) {
            abort(404);
        }

        if ($template->is_system && ! $request->user()->isStoreOwner()) {
            return redirect()->back()->with('error', 'Cannot modify system templates.');
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'subject' => ['nullable', 'string', 'max:255'],
            'content' => ['sometimes', 'string'],
            'available_variables' => ['nullable', 'array'],
            'category' => ['nullable', 'string', 'max:50'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);

        $template->update($validated);

        return redirect()->back()->with('success', 'Template updated.');
    }

    public function destroyTemplate(Request $request, NotificationTemplate $template): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();
        if (! $store || $template->store_id !== $store->id) {
            abort(404);
        }

        if ($template->is_system) {
            return redirect()->back()->with('error', 'Cannot delete system templates.');
        }

        if ($template->subscriptions()->count() > 0) {
            return redirect()->back()->with('error', 'Cannot delete template with active subscriptions.');
        }

        $template->delete();

        return redirect()->route('settings.notifications.templates')->with('success', 'Template deleted.');
    }

    public function duplicateTemplate(NotificationTemplate $template): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();
        if (! $store || $template->store_id !== $store->id) {
            abort(404);
        }

        $newTemplate = $template->replicate();
        $newTemplate->name = $template->name.' (Copy)';
        $newTemplate->slug = $template->slug.'-copy';
        $newTemplate->is_system = false;
        $newTemplate->save();

        return redirect()->back()->with('success', 'Template duplicated.');
    }

    public function createDefaultTemplates(): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();
        if (! $store) {
            return redirect()->route('dashboard')->with('error', 'Please select a store first.');
        }

        NotificationTemplate::createDefaultTemplates($store->id);
        NotificationSubscription::createDefaultSubscriptions($store->id);

        return redirect()->back()->with('success', 'Default templates and subscriptions created.');
    }

    public function layouts(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();
        if (! $store) {
            return redirect()->route('dashboard')->with('error', 'Please select a store first.');
        }

        $layouts = NotificationLayout::where('store_id', $store->id)
            ->withCount('templates')
            ->orderBy('channel')
            ->orderBy('name')
            ->get();

        return Inertia::render('settings/notifications/Layouts', [
            'layouts' => $layouts,
            'channelTypes' => NotificationChannel::TYPES,
        ]);
    }

    public function createLayout(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();
        if (! $store) {
            return redirect()->route('dashboard')->with('error', 'Please select a store first.');
        }

        return Inertia::render('settings/notifications/LayoutEditor', [
            'layout' => null,
            'channelTypes' => NotificationChannel::TYPES,
            'sampleData' => $this->dataPreparer->getSampleData(),
        ]);
    }

    public function storeLayout(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();
        if (! $store) {
            return redirect()->route('dashboard')->with('error', 'Please select a store first.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/'],
            'channel' => ['required', 'string', Rule::in(NotificationChannel::TYPES)],
            'content' => ['required', 'string'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_default' => ['nullable', 'boolean'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $exists = NotificationLayout::where('store_id', $store->id)
            ->where('slug', $validated['slug'])
            ->where('channel', $validated['channel'])
            ->exists();

        if ($exists) {
            return redirect()->back()->withErrors([
                'slug' => 'A layout with this slug already exists for this channel.',
            ])->withInput();
        }

        if ($validated['is_default'] ?? false) {
            NotificationLayout::where('store_id', $store->id)
                ->where('channel', $validated['channel'])
                ->update(['is_default' => false]);
        }

        NotificationLayout::create([
            ...$validated,
            'store_id' => $store->id,
            'is_enabled' => $validated['is_enabled'] ?? true,
        ]);

        return redirect()->route('settings.notifications.layouts')->with('success', 'Layout created.');
    }

    public function editLayout(NotificationLayout $layout): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();
        if (! $store || $layout->store_id !== $store->id) {
            abort(404);
        }

        return Inertia::render('settings/notifications/LayoutEditor', [
            'layout' => $layout,
            'channelTypes' => NotificationChannel::TYPES,
            'sampleData' => $this->dataPreparer->getSampleData(),
        ]);
    }

    public function updateLayout(Request $request, NotificationLayout $layout): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();
        if (! $store || $layout->store_id !== $store->id) {
            abort(404);
        }

        if ($layout->is_system && ! $request->user()->isStoreOwner()) {
            return redirect()->back()->with('error', 'Cannot modify system layouts.');
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'content' => ['sometimes', 'string'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_default' => ['nullable', 'boolean'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);

        if ($validated['is_default'] ?? false) {
            NotificationLayout::where('store_id', $layout->store_id)
                ->where('channel', $layout->channel)
                ->where('id', '!=', $layout->id)
                ->update(['is_default' => false]);
        }

        $layout->update($validated);

        return redirect()->back()->with('success', 'Layout updated.');
    }

    public function destroyLayout(NotificationLayout $layout): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();
        if (! $store || $layout->store_id !== $store->id) {
            abort(404);
        }

        if ($layout->is_system) {
            return redirect()->back()->with('error', 'Cannot delete system layouts.');
        }

        if ($layout->templates()->count() > 0) {
            return redirect()->back()->with('error', 'Cannot delete layout with assigned templates.');
        }

        $layout->delete();

        return redirect()->route('settings.notifications.layouts')->with('success', 'Layout deleted.');
    }

    public function setDefaultLayout(NotificationLayout $layout): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();
        if (! $store || $layout->store_id !== $store->id) {
            abort(404);
        }

        NotificationLayout::where('store_id', $layout->store_id)
            ->where('channel', $layout->channel)
            ->where('id', '!=', $layout->id)
            ->update(['is_default' => false]);

        $layout->update(['is_default' => true]);

        return redirect()->back()->with('success', 'Default layout updated.');
    }

    public function createDefaultLayouts(): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();
        if (! $store) {
            return redirect()->route('dashboard')->with('error', 'Please select a store first.');
        }

        NotificationLayout::createDefaultLayouts($store->id);

        return redirect()->back()->with('success', 'Default layouts created.');
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
