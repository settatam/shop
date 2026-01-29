<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLabelTemplateRequest;
use App\Http\Requests\UpdateLabelTemplateRequest;
use App\Models\LabelTemplate;
use App\Models\LabelTemplateElement;
use App\Services\LabelFieldsService;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class LabelTemplateController extends Controller
{
    public function __construct(protected StoreContext $storeContext) {}

    public function index(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $templates = LabelTemplate::where('store_id', $store->id)
            ->withCount('elements')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn ($template) => [
                'id' => $template->id,
                'name' => $template->name,
                'type' => $template->type,
                'type_label' => LabelTemplate::getTypes()[$template->type] ?? $template->type,
                'canvas_width' => $template->canvas_width,
                'canvas_height' => $template->canvas_height,
                'is_default' => $template->is_default,
                'elements_count' => $template->elements_count,
                'updated_at' => $template->updated_at->format('M j, Y'),
            ]);

        return Inertia::render('labels/Index', [
            'templates' => $templates,
            'types' => LabelTemplate::getTypes(),
        ]);
    }

    public function create(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        return Inertia::render('labels/Designer', [
            'template' => null,
            'types' => LabelTemplate::getTypes(),
            'elementTypes' => LabelTemplateElement::getElementTypes(),
            'productFields' => LabelFieldsService::getProductFields(),
            'transactionFields' => LabelFieldsService::getTransactionFields(),
            'sampleProductData' => LabelFieldsService::getSampleData('product'),
            'sampleTransactionData' => LabelFieldsService::getSampleData('transaction'),
        ]);
    }

    public function store(StoreLabelTemplateRequest $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $template = LabelTemplate::create([
            'store_id' => $store->id,
            'name' => $request->name,
            'type' => $request->type,
            'canvas_width' => $request->canvas_width,
            'canvas_height' => $request->canvas_height,
            'is_default' => $request->boolean('is_default'),
        ]);

        // Create elements
        if ($request->has('elements')) {
            foreach ($request->elements as $index => $elementData) {
                $template->elements()->create([
                    ...$elementData,
                    'sort_order' => $elementData['sort_order'] ?? $index,
                ]);
            }
        }

        // Handle default status
        if ($request->boolean('is_default')) {
            $template->makeDefault();
        }

        // If this is the first template, make it default
        $templateCount = LabelTemplate::where('store_id', $store->id)
            ->where('type', $template->type)
            ->count();

        if ($templateCount === 1) {
            $template->update(['is_default' => true]);
        }

        return redirect()->route('labels.index')
            ->with('success', 'Label template created successfully.');
    }

    public function edit(LabelTemplate $label): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $label->store_id !== $store->id) {
            abort(404);
        }

        return Inertia::render('labels/Designer', [
            'template' => [
                'id' => $label->id,
                'name' => $label->name,
                'type' => $label->type,
                'canvas_width' => $label->canvas_width,
                'canvas_height' => $label->canvas_height,
                'is_default' => $label->is_default,
                'elements' => $label->elements->map(fn ($element) => [
                    'id' => $element->id,
                    'element_type' => $element->element_type,
                    'x' => $element->x,
                    'y' => $element->y,
                    'width' => $element->width,
                    'height' => $element->height,
                    'content' => $element->content,
                    'styles' => $element->styles ?? [],
                    'sort_order' => $element->sort_order,
                ]),
            ],
            'types' => LabelTemplate::getTypes(),
            'elementTypes' => LabelTemplateElement::getElementTypes(),
            'productFields' => LabelFieldsService::getProductFields(),
            'transactionFields' => LabelFieldsService::getTransactionFields(),
            'sampleProductData' => LabelFieldsService::getSampleData('product'),
            'sampleTransactionData' => LabelFieldsService::getSampleData('transaction'),
        ]);
    }

    public function update(UpdateLabelTemplateRequest $request, LabelTemplate $label): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $label->store_id !== $store->id) {
            abort(404);
        }

        $label->update([
            'name' => $request->name,
            'type' => $request->type,
            'canvas_width' => $request->canvas_width,
            'canvas_height' => $request->canvas_height,
            'is_default' => $request->boolean('is_default'),
        ]);

        // Delete existing elements and recreate
        $label->elements()->delete();

        if ($request->has('elements')) {
            foreach ($request->elements as $index => $elementData) {
                $label->elements()->create([
                    ...$elementData,
                    'sort_order' => $elementData['sort_order'] ?? $index,
                ]);
            }
        }

        // Handle default status
        if ($request->boolean('is_default')) {
            $label->makeDefault();
        }

        return redirect()->route('labels.index')
            ->with('success', 'Label template updated successfully.');
    }

    public function destroy(LabelTemplate $label): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $label->store_id !== $store->id) {
            abort(404);
        }

        $wasDefault = $label->is_default;
        $type = $label->type;
        $label->delete();

        // If deleted was default, make another one default
        if ($wasDefault) {
            $newDefault = LabelTemplate::where('store_id', $store->id)
                ->where('type', $type)
                ->first();

            if ($newDefault) {
                $newDefault->update(['is_default' => true]);
            }
        }

        return redirect()->route('labels.index')
            ->with('success', 'Label template deleted successfully.');
    }

    public function duplicate(LabelTemplate $label): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $label->store_id !== $store->id) {
            abort(404);
        }

        // Find a unique name
        $baseName = $label->name.' (Copy)';
        $newName = $baseName;
        $counter = 1;

        while (LabelTemplate::where('store_id', $store->id)->where('name', $newName)->exists()) {
            $counter++;
            $newName = $baseName.' '.$counter;
        }

        // Create the duplicate
        $duplicate = $label->replicate();
        $duplicate->name = $newName;
        $duplicate->is_default = false;
        $duplicate->save();

        // Duplicate elements
        foreach ($label->elements as $element) {
            $newElement = $element->replicate();
            $newElement->label_template_id = $duplicate->id;
            $newElement->save();
        }

        return redirect()->route('labels.edit', $duplicate)
            ->with('success', 'Label template duplicated successfully.');
    }
}
