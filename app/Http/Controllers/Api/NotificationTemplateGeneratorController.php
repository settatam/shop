<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationTemplate;
use App\Models\Store;
use App\Services\AI\EmailTemplateGenerator;
use App\Services\Reports\ReportFieldRegistry;
use App\Services\Reports\ReportRegistry;
use App\Services\Reports\ReportStructure;
use App\Services\Reports\ReportStructureGenerator;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationTemplateGeneratorController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected EmailTemplateGenerator $templateGenerator,
        protected ReportStructureGenerator $structureGenerator,
    ) {}

    /**
     * Get available fields that can be used in reports.
     * Helps users understand what they can ask for.
     */
    public function fields(): JsonResponse
    {
        $registry = new ReportFieldRegistry($this->storeContext->getCurrentStoreId());

        return response()->json([
            'success' => true,
            'data' => [
                'fields' => $registry->getAllFields(),
                'categories' => $registry->getFieldsByCategory(),
            ],
        ]);
    }

    /**
     * Create a report structure from natural language.
     *
     * Example: "Create a daily sales report with order number, customer, total, and date"
     */
    public function createStructure(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'description' => ['required', 'string', 'max:2000'],
            'report_type' => ['nullable', 'string'],
        ]);

        try {
            $structure = $this->structureGenerator->createStructure(
                $validated['description'],
                ['report_type' => $validated['report_type'] ?? 'custom']
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'structure' => $structure->toArray(),
                    'sample_data' => $structure->generateSampleData(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Modify an existing structure based on user request.
     *
     * Examples: "Remove the profit column", "Add customer phone"
     */
    public function modifyStructure(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'structure' => ['required', 'array'],
            'modification' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $currentStructure = ReportStructure::fromArray($validated['structure']);

            $modifiedStructure = $this->structureGenerator->modifyStructure(
                $currentStructure,
                $validated['modification']
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'structure' => $modifiedStructure->toArray(),
                    'sample_data' => $modifiedStructure->generateSampleData(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate a Twig template from a structure.
     */
    public function generateFromStructure(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'structure' => ['required', 'array'],
        ]);

        try {
            $structure = ReportStructure::fromArray($validated['structure']);
            $template = $this->structureGenerator->generateTemplate($structure);

            return response()->json([
                'success' => true,
                'data' => [
                    'subject' => $template['subject'],
                    'content' => $template['content'],
                    'variables' => $template['variables'],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Full flow: description → structure → template → save.
     */
    public function createReport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/'],
            'description' => ['required', 'string', 'max:2000'],
            'report_type' => ['nullable', 'string'],
            'category' => ['nullable', 'string'],
        ]);

        $storeId = $this->storeContext->getCurrentStoreId();

        // Check if template with this slug already exists
        $existing = NotificationTemplate::where('store_id', $storeId)
            ->where('slug', $validated['slug'])
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'error' => 'A template with this slug already exists.',
            ], 422);
        }

        try {
            $template = $this->structureGenerator->createReportTemplate(
                $validated['description'],
                $storeId,
                $validated['name'],
                $validated['slug'],
                [
                    'report_type' => $validated['report_type'] ?? 'custom',
                    'category' => $validated['category'] ?? 'reports',
                ]
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $template->id,
                    'name' => $template->name,
                    'slug' => $template->slug,
                    'subject' => $template->subject,
                    'content' => $template->content,
                    'structure' => $template->structure,
                    'available_variables' => $template->available_variables,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update an existing template's structure and regenerate.
     */
    public function updateReport(Request $request, NotificationTemplate $template): JsonResponse
    {
        $storeId = $this->storeContext->getCurrentStoreId();

        if ($template->store_id !== $storeId) {
            abort(403);
        }

        $validated = $request->validate([
            'modification' => ['required', 'string', 'max:1000'],
        ]);

        if (! $template->structure) {
            return response()->json([
                'success' => false,
                'error' => 'This template does not have a structure. Cannot modify.',
            ], 400);
        }

        try {
            $currentStructure = ReportStructure::fromArray($template->structure);

            // Modify structure
            $modifiedStructure = $this->structureGenerator->modifyStructure(
                $currentStructure,
                $validated['modification']
            );

            // Regenerate template
            $newTemplate = $this->structureGenerator->generateTemplate($modifiedStructure);

            // Update
            $template->update([
                'subject' => $newTemplate['subject'],
                'content' => $newTemplate['content'],
                'structure' => $modifiedStructure->toArray(),
                'available_variables' => $newTemplate['variables'],
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $template->id,
                    'subject' => $template->subject,
                    'content' => $template->content,
                    'structure' => $template->structure,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Preview a template with sample or real data.
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string'],
            'content' => ['required', 'string'],
            'data' => ['nullable', 'array'],
            'structure' => ['nullable', 'array'],
        ]);

        try {
            // If structure provided, generate sample data from it
            if ($validated['structure'] ?? null) {
                $structure = ReportStructure::fromArray($validated['structure']);
                $sampleData = $structure->generateSampleData();
            } else {
                $sampleData = $validated['data'] ?? $this->getDefaultSampleData();
            }

            $renderedSubject = NotificationTemplate::renderTwig($validated['subject'], $sampleData);
            $renderedContent = NotificationTemplate::renderTwig($validated['content'], $sampleData);

            return response()->json([
                'success' => true,
                'data' => [
                    'subject' => $renderedSubject,
                    'content' => $renderedContent,
                    'sample_data_used' => $sampleData,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Template rendering failed: '.$e->getMessage(),
            ], 400);
        }
    }

    /**
     * List all available report classes.
     * Used for dropdown selection in the UI.
     */
    public function reportClasses(ReportRegistry $registry): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'reports' => $registry->getDropdownOptions(),
            ],
        ]);
    }

    /**
     * Create a template from a developer-defined report class.
     *
     * Flow: User selects class → System generates structure → Generates template → Saves to DB
     */
    public function createFromClass(Request $request, ReportRegistry $registry): JsonResponse
    {
        $validated = $request->validate([
            'report_type' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/'],
        ]);

        $storeId = $this->storeContext->getCurrentStoreId();
        $store = Store::findOrFail($storeId);

        // Get the report class
        $report = $registry->makeReport($validated['report_type'], $store);

        if (! $report) {
            return response()->json([
                'success' => false,
                'error' => 'Report type not found: '.$validated['report_type'],
            ], 404);
        }

        // Get or generate name/slug
        $name = $validated['name'] ?? $report->getName();
        $slug = $validated['slug'] ?? $report->getSlug();

        // Check if template with this slug already exists
        $existing = NotificationTemplate::where('store_id', $storeId)
            ->where('slug', $slug)
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'error' => 'A template with this slug already exists.',
                'existing_id' => $existing->id,
            ], 422);
        }

        try {
            // Get structure from the class
            $structure = $report->getStructure();

            // Generate Twig template from structure
            $template = $this->structureGenerator->generateTemplate($structure);

            // Save to database
            $notificationTemplate = NotificationTemplate::create([
                'store_id' => $storeId,
                'name' => $name,
                'slug' => $slug,
                'description' => "Generated from {$report->getName()} class",
                'channel' => 'email',
                'subject' => $template['subject'],
                'content' => $template['content'],
                'structure' => $structure->toArray(),
                'template_type' => 'structured',
                'available_variables' => $template['variables'],
                'category' => 'reports',
                'is_enabled' => true,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $notificationTemplate->id,
                    'name' => $notificationTemplate->name,
                    'slug' => $notificationTemplate->slug,
                    'subject' => $notificationTemplate->subject,
                    'content' => $notificationTemplate->content,
                    'structure' => $notificationTemplate->structure,
                    'available_variables' => $notificationTemplate->available_variables,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get the structure from a report class without saving.
     * Useful for previewing before creating.
     */
    public function getClassStructure(Request $request, ReportRegistry $registry): JsonResponse
    {
        $validated = $request->validate([
            'report_type' => ['required', 'string'],
        ]);

        $storeId = $this->storeContext->getCurrentStoreId();
        $store = Store::findOrFail($storeId);

        $report = $registry->makeReport($validated['report_type'], $store);

        if (! $report) {
            return response()->json([
                'success' => false,
                'error' => 'Report type not found: '.$validated['report_type'],
            ], 404);
        }

        $structure = $report->getStructure();

        return response()->json([
            'success' => true,
            'data' => [
                'type' => $report->getType(),
                'name' => $report->getName(),
                'slug' => $report->getSlug(),
                'structure' => $structure->toArray(),
                'sample_data' => $structure->generateSampleData(),
            ],
        ]);
    }

    // Legacy methods for backward compatibility

    /**
     * Generate an email template from a description (legacy).
     */
    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'description' => ['required', 'string', 'max:2000'],
            'variables' => ['nullable', 'array'],
            'variables.*' => ['string'],
            'sample_data' => ['nullable', 'array'],
            'style' => ['nullable', 'string', 'in:professional,casual,formal'],
            'tone' => ['nullable', 'string'],
        ]);

        try {
            $result = $this->templateGenerator->generate(
                $validated['description'],
                $validated['variables'] ?? ['store', 'date'],
                [
                    'sample_data' => $validated['sample_data'] ?? null,
                    'style' => $validated['style'] ?? 'professional',
                    'tone' => $validated['tone'] ?? 'friendly but professional',
                ]
            );

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate a report-specific template (legacy).
     */
    public function generateReport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'report_type' => ['required', 'string', 'in:daily_sales,daily_buy,daily_memos,daily_repairs'],
            'description' => ['required', 'string', 'max:2000'],
            'sample_data' => ['nullable', 'array'],
        ]);

        try {
            $result = $this->templateGenerator->generateReportTemplate(
                $validated['report_type'],
                $validated['description'],
                $validated['sample_data'] ?? []
            );

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    protected function getDefaultSampleData(): array
    {
        return [
            'date' => 'Daily Report for '.now()->format('m-d-Y'),
            'store' => [
                'name' => 'Sample Store',
                'email' => 'info@store.com',
                'phone' => '(555) 123-4567',
            ],
            'data' => [
                [now()->format('m-d-Y'), 'ORD-001', 'John Doe', '$1,250.00', 'Completed'],
                [now()->format('m-d-Y'), 'ORD-002', 'Jane Smith', '$890.50', 'Processing'],
            ],
            'headings' => ['Date', 'Order #', 'Customer', 'Total', 'Status'],
        ];
    }
}
