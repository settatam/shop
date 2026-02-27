<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreKnowledgeBaseRequest;
use App\Models\StoreKnowledgeBaseEntry;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KnowledgeBaseController extends Controller
{
    public function __construct(protected StoreContext $storeContext) {}

    public function index(): Response
    {
        $store = $this->storeContext->getCurrentStore();

        $entries = StoreKnowledgeBaseEntry::where('store_id', $store->id)
            ->orderBy('type')
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('settings/KnowledgeBase', [
            'entries' => $entries,
            'types' => collect(StoreKnowledgeBaseEntry::VALID_TYPES)->map(fn (string $type) => [
                'value' => $type,
                'label' => StoreKnowledgeBaseEntry::getTypeLabel($type),
            ])->values(),
        ]);
    }

    public function store(StoreKnowledgeBaseRequest $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        $maxSortOrder = StoreKnowledgeBaseEntry::where('store_id', $store->id)
            ->where('type', $request->type)
            ->max('sort_order') ?? -1;

        StoreKnowledgeBaseEntry::create([
            'store_id' => $store->id,
            'type' => $request->type,
            'title' => $request->title,
            'content' => $request->content,
            'is_active' => $request->boolean('is_active', true),
            'sort_order' => $maxSortOrder + 1,
        ]);

        return back()->with('success', 'Knowledge base entry created.');
    }

    public function update(StoreKnowledgeBaseRequest $request, StoreKnowledgeBaseEntry $entry): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if ($entry->store_id !== $store->id) {
            abort(404);
        }

        $entry->update([
            'type' => $request->type,
            'title' => $request->title,
            'content' => $request->content,
            'is_active' => $request->boolean('is_active', $entry->is_active),
        ]);

        return back()->with('success', 'Knowledge base entry updated.');
    }

    public function destroy(StoreKnowledgeBaseEntry $entry): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if ($entry->store_id !== $store->id) {
            abort(404);
        }

        $entry->delete();

        return back()->with('success', 'Knowledge base entry deleted.');
    }

    public function reorder(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        $request->validate([
            'entries' => ['required', 'array'],
            'entries.*.id' => ['required', 'integer'],
            'entries.*.sort_order' => ['required', 'integer', 'min:0'],
        ]);

        foreach ($request->entries as $item) {
            StoreKnowledgeBaseEntry::where('id', $item['id'])
                ->where('store_id', $store->id)
                ->update(['sort_order' => $item['sort_order']]);
        }

        return back()->with('success', 'Order updated.');
    }
}
