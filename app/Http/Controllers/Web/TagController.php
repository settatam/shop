<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TagController extends Controller
{
    public function __construct(protected StoreContext $storeContext) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $query = Tag::where('store_id', $store->id)
            ->withCount(['products', 'transactions', 'memos', 'repairs']);

        if ($request->has('search') && $request->input('search')) {
            $query->search($request->input('search'));
        }

        $sortField = $request->input('sort', 'name');
        $sortDirection = $request->input('direction', 'asc');
        $query->orderBy($sortField, $sortDirection);

        $tags = $query->paginate($request->input('per_page', 25))
            ->through(fn ($tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'color' => $tag->color,
                'products_count' => $tag->products_count,
                'transactions_count' => $tag->transactions_count,
                'memos_count' => $tag->memos_count,
                'repairs_count' => $tag->repairs_count,
                'total_usage' => $tag->products_count + $tag->transactions_count + $tag->memos_count + $tag->repairs_count,
                'created_at' => $tag->created_at->toISOString(),
            ]);

        return Inertia::render('tags/Index', [
            'tags' => $tags,
            'filters' => [
                'search' => $request->input('search', ''),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            if ($request->wantsJson()) {
                return response()->json(['error' => 'Please select a store first.'], 400);
            }

            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:191',
            'color' => 'nullable|string|max:20',
        ]);

        $tag = Tag::create([
            'store_id' => $store->id,
            'name' => $validated['name'],
            'color' => $validated['color'] ?? '#6b7280',
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'color' => $tag->color,
            ]);
        }

        return redirect()->route('web.tags.index')
            ->with('success', 'Tag created successfully.');
    }

    public function update(Request $request, Tag $tag): RedirectResponse|JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $tag->store_id !== $store->id) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:191',
            'color' => 'nullable|string|max:20',
        ]);

        $tag->update($validated);

        if ($request->wantsJson()) {
            return response()->json([
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'color' => $tag->color,
            ]);
        }

        return redirect()->back()
            ->with('success', 'Tag updated successfully.');
    }

    public function destroy(Tag $tag): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $tag->store_id !== $store->id) {
            abort(404);
        }

        $tag->delete();

        return redirect()->route('web.tags.index')
            ->with('success', 'Tag deleted successfully.');
    }

    /**
     * Search tags for autocomplete (AJAX endpoint).
     */
    public function search(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json([]);
        }

        $search = $request->input('q', '');

        $tags = Tag::where('store_id', $store->id)
            ->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'slug', 'color']);

        return response()->json($tags);
    }
}
