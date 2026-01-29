<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Platform;
use App\Services\AI\TemplateGeneratorService;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TemplateGeneratorController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected TemplateGeneratorService $templateGenerator
    ) {}

    /**
     * Show the template generator wizard.
     */
    public function index(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $platforms = Platform::active()->get()->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'logo_url' => $p->logo_url,
        ]);

        return Inertia::render('templates/Generator', [
            'platforms' => $platforms,
        ]);
    }

    /**
     * Generate a template preview from the user's prompt.
     */
    public function preview(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json([
                'error' => 'Please select a store first.',
            ], 400);
        }

        $validated = $request->validate([
            'prompt' => 'required|string|min:3|max:500',
        ]);

        try {
            $result = $this->templateGenerator->generateFromPrompt(
                $validated['prompt'],
                $store
            );

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to generate template: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate a template preview from an eBay category.
     */
    public function previewFromEbayCategory(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json([
                'error' => 'Please select a store first.',
            ], 400);
        }

        $validated = $request->validate([
            'ebay_category_id' => 'required|integer|exists:ebay_categories,id',
        ]);

        try {
            $result = $this->templateGenerator->generateFromEbayCategory(
                $validated['ebay_category_id'],
                $store
            );

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to generate template: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create the template from the AI-generated response.
     */
    public function store(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $validated = $request->validate([
            'template_data' => 'required|array',
            'template_data.category' => 'required|array',
            'template_data.category.name' => 'required|string|max:255',
            'template_data.template' => 'required|array',
            'template_data.template.name' => 'required|string|max:255',
            'template_data.fields' => 'required|array',
            'original_prompt' => 'nullable|string|max:500',
        ]);

        try {
            $templateData = $validated['template_data'];
            $templateData['original_prompt'] = $validated['original_prompt'] ?? null;

            $template = $this->templateGenerator->createFromAIResponse(
                $templateData,
                $store
            );

            return redirect()->route('templates.show', $template)
                ->with('success', 'Template created successfully using AI.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Failed to create template: '.$e->getMessage());
        }
    }
}
