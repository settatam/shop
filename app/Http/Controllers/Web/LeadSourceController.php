<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLeadSourceRequest;
use App\Models\LeadSource;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeadSourceController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
    ) {}

    /**
     * Display the lead sources settings page.
     */
    public function settings(): Response
    {
        $store = $this->storeContext->getCurrentStore();

        $leadSources = LeadSource::where('store_id', $store->id)
            ->withCount('customers')
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('settings/LeadSources', [
            'leadSources' => $leadSources,
        ]);
    }

    public function index(): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        $leadSources = LeadSource::where('store_id', $store->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug', 'description']);

        return response()->json($leadSources);
    }

    public function store(StoreLeadSourceRequest $request): JsonResponse|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        // Get the next sort order
        $maxSortOrder = LeadSource::where('store_id', $store->id)->max('sort_order') ?? -1;

        $leadSource = LeadSource::create([
            'store_id' => $store->id,
            'name' => $request->name,
            'description' => $request->description,
            'sort_order' => $maxSortOrder + 1,
        ]);

        // Return JSON for AJAX requests, redirect for form submissions
        if ($request->wantsJson()) {
            return response()->json([
                'id' => $leadSource->id,
                'name' => $leadSource->name,
                'slug' => $leadSource->slug,
                'description' => $leadSource->description,
            ], 201);
        }

        return back()->with('success', 'Lead source created successfully.');
    }

    public function update(Request $request, LeadSource $leadSource): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if ($leadSource->store_id !== $store->id) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ]);

        $leadSource->update($validated);

        return back()->with('success', 'Lead source updated successfully.');
    }

    public function destroy(LeadSource $leadSource): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if ($leadSource->store_id !== $store->id) {
            abort(404);
        }

        // Check if lead source is in use
        $customerCount = $leadSource->customers()->count();
        if ($customerCount > 0) {
            return back()->with('error', "Cannot delete lead source - it is assigned to {$customerCount} customer(s). Deactivate it instead.");
        }

        $leadSource->delete();

        return back()->with('success', 'Lead source deleted successfully.');
    }

    public function reorder(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        $validated = $request->validate([
            'order' => 'required|array',
            'order.*' => 'required|integer|exists:lead_sources,id',
        ]);

        foreach ($validated['order'] as $index => $id) {
            LeadSource::where('id', $id)
                ->where('store_id', $store->id)
                ->update(['sort_order' => $index]);
        }

        return back()->with('success', 'Lead sources reordered successfully.');
    }
}
