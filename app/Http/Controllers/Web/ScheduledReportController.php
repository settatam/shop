<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Mail\DynamicReportMail;
use App\Models\NotificationTemplate;
use App\Models\ScheduledReport;
use App\Services\Reports\ReportRegistry;
use App\Services\StoreContext;
use Carbon\Carbon;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class ScheduledReportController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected ReportRegistry $reportRegistry,
    ) {}

    /**
     * Display the scheduled reports settings page.
     */
    public function index(): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $reportTypes = $this->reportRegistry->getDropdownOptions();

        $scheduledReports = ScheduledReport::where('store_id', $store->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($report) use ($reportTypes) {
                $reportType = collect($reportTypes)->firstWhere('value', $report->report_type);

                return [
                    'id' => $report->id,
                    'report_type' => $report->report_type,
                    'report_slug' => $reportType['slug'] ?? null,
                    'template_id' => $report->template_id,
                    'name' => $report->name,
                    'display_name' => $report->display_name,
                    'recipients' => $report->recipients,
                    'schedule_time' => $report->schedule_time,
                    'timezone' => $report->timezone,
                    'schedule_days' => $report->schedule_days,
                    'schedule_description' => $report->schedule_description,
                    'is_enabled' => $report->is_enabled,
                    'last_sent_at' => $report->last_sent_at?->format('M j, Y g:i A'),
                    'last_failed_at' => $report->last_failed_at?->format('M j, Y g:i A'),
                    'last_error' => $report->last_error,
                ];
            });

        $timezones = collect(DateTimeZone::listIdentifiers(DateTimeZone::AMERICA))
            ->map(fn ($tz) => ['value' => $tz, 'label' => $tz])
            ->values()
            ->toArray();

        return Inertia::render('settings/notifications/ScheduledReports', [
            'scheduledReports' => $scheduledReports,
            'reportTypes' => $reportTypes,
            'timezones' => $timezones,
        ]);
    }

    /**
     * Store a new scheduled report.
     */
    public function store(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        $validated = $request->validate([
            'report_type' => 'required|string',
            'name' => 'nullable|string|max:255',
            'recipients' => 'required|array|min:1',
            'recipients.*' => 'required|email',
            'schedule_time' => 'required|date_format:H:i',
            'timezone' => 'required|string|timezone',
            'schedule_days' => 'nullable|array',
            'schedule_days.*' => 'integer|between:0,6',
            'is_enabled' => 'boolean',
        ]);

        // Verify report type exists
        if (! $this->reportRegistry->exists($validated['report_type'])) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid report type',
            ], 422);
        }

        $scheduledReport = ScheduledReport::create([
            'store_id' => $store->id,
            'report_type' => $validated['report_type'],
            'name' => $validated['name'] ?? null,
            'recipients' => $validated['recipients'],
            'schedule_time' => $validated['schedule_time'],
            'timezone' => $validated['timezone'],
            'schedule_days' => $validated['schedule_days'] ?? null,
            'is_enabled' => $validated['is_enabled'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Scheduled report created successfully',
            'data' => [
                'id' => $scheduledReport->id,
            ],
        ]);
    }

    /**
     * Update a scheduled report.
     */
    public function update(Request $request, ScheduledReport $scheduledReport): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if ($scheduledReport->store_id !== $store->id) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'report_type' => 'sometimes|required|string',
            'name' => 'nullable|string|max:255',
            'recipients' => 'sometimes|required|array|min:1',
            'recipients.*' => 'required|email',
            'schedule_time' => 'sometimes|required|date_format:H:i',
            'timezone' => 'sometimes|required|string|timezone',
            'schedule_days' => 'nullable|array',
            'schedule_days.*' => 'integer|between:0,6',
            'is_enabled' => 'boolean',
        ]);

        // Verify report type exists if provided
        if (isset($validated['report_type']) && ! $this->reportRegistry->exists($validated['report_type'])) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid report type',
            ], 422);
        }

        $scheduledReport->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Scheduled report updated successfully',
        ]);
    }

    /**
     * Delete a scheduled report.
     */
    public function destroy(ScheduledReport $scheduledReport): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if ($scheduledReport->store_id !== $store->id) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 403);
        }

        $scheduledReport->delete();

        return response()->json([
            'success' => true,
            'message' => 'Scheduled report deleted successfully',
        ]);
    }

    /**
     * Send a test report.
     */
    public function test(Request $request, ScheduledReport $scheduledReport): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if ($scheduledReport->store_id !== $store->id) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 403);
        }

        try {
            // Generate the report for yesterday
            $reportDate = Carbon::yesterday($scheduledReport->timezone);
            $report = $this->reportRegistry->makeReport($scheduledReport->report_type, $store, $reportDate);

            if (! $report) {
                return response()->json([
                    'success' => false,
                    'error' => 'Report type not found',
                ], 404);
            }

            $data = $report->getData();
            $structure = $report->getStructure();

            // Build email content
            $structureArray = $structure->toArray();
            $tables = [];
            $totalRowCount = 0;

            foreach ($structureArray['tables'] as $table) {
                $dataKey = $table['data_key'] ?? $table['dataKey'] ?? $table['name'];
                $tableData = $data[$dataKey] ?? [];
                $tables[] = [
                    'heading' => $table['heading'],
                    'columns' => $table['columns'],
                    'rows' => $tableData,
                ];

                if (count($tableData) > $totalRowCount) {
                    $totalRowCount = count($tableData);
                }
            }

            $reportTitle = "{$report->getName()} - {$store->name}";
            $subject = "[TEST] {$reportTitle} - {$reportDate->format('M j, Y')}";

            // Send to first recipient only for test
            $testRecipient = $scheduledReport->recipients[0] ?? null;
            if (! $testRecipient) {
                return response()->json([
                    'success' => false,
                    'error' => 'No recipients configured',
                ], 422);
            }

            $mailable = (new DynamicReportMail(
                reportTitle: $reportTitle,
                description: "Test email for {$report->getName()}",
                content: ['tables' => $tables],
                rowCount: $totalRowCount,
                generatedAt: $reportDate
            ))->withSubject($subject);

            // Set from address using store settings
            $fromAddress = $store->email_from_address ?: config('mail.from.address');
            $fromName = $store->email_from_name ?: config('mail.from.name', $store->name);
            $mailable->from($fromAddress, $fromName);

            if ($store->email_reply_to_address) {
                $mailable->replyTo($store->email_reply_to_address);
            }

            Mail::to($testRecipient)->send($mailable);

            return response()->json([
                'success' => true,
                'message' => "Test report sent to {$testRecipient}",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to send test report: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Edit or create a template for this scheduled report.
     */
    public function editTemplate(ScheduledReport $scheduledReport): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if ($scheduledReport->store_id !== $store->id) {
            abort(403);
        }

        // If the report already has a template, redirect to edit it
        if ($scheduledReport->template_id) {
            return redirect()->route('settings.notifications.templates.edit', [
                'template' => $scheduledReport->template_id,
            ]);
        }

        // Create a new template from the report structure
        $report = $this->reportRegistry->makeReport($scheduledReport->report_type, $store);

        if (! $report) {
            return redirect()->back()->with('error', 'Report type not found');
        }

        $structure = $report->getStructure();

        // Generate a basic Twig template from the structure
        $template = NotificationTemplate::create([
            'store_id' => $store->id,
            'name' => $scheduledReport->display_name.' Template',
            'slug' => $report->getSlug().'-'.time(),
            'description' => 'Template for '.$scheduledReport->display_name,
            'channel' => 'email',
            'subject' => '{{ report_title }} - {{ date|date("M j, Y") }}',
            'content' => $this->generateDefaultTemplateContent($structure),
            'structure' => $structure->toArray(),
            'template_type' => 'structured',
            'available_variables' => $this->extractTemplateVariables($structure),
            'category' => 'reports',
            'is_enabled' => true,
        ]);

        // Link the template to the scheduled report
        $scheduledReport->update(['template_id' => $template->id]);

        return redirect()->route('settings.notifications.templates.edit', [
            'template' => $template->id,
        ]);
    }

    /**
     * Generate default Twig template content from a report structure.
     */
    protected function generateDefaultTemplateContent($structure): string
    {
        $html = <<<'HTML'
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 800px; margin: 0 auto;">
    <h1 style="color: #111827;">{{ report_title }}</h1>
    {% if description %}
        <p style="color: #6b7280;">{{ description }}</p>
    {% endif %}

    <p style="color: #6b7280; font-size: 14px;">
        Generated: {{ generated_at|date("M j, Y g:i A") }} | Results: {{ row_count }} rows
    </p>

HTML;

        foreach ($structure->getTables() as $table) {
            $dataKey = $table['data_key'] ?? $table['name'];
            $heading = $table['heading'] ?? ucwords(str_replace('_', ' ', $table['name']));

            $html .= <<<HTML

    <h2 style="color: #374151; border-bottom: 1px solid #e5e7eb; padding-bottom: 8px; margin-top: 24px;">{$heading}</h2>

    {% if {$dataKey} is not empty %}
    <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
        <thead>
            <tr style="background: #f3f4f6;">

HTML;

            foreach ($table['columns'] as $column) {
                $label = $column['label'] ?? $column['key'];
                $html .= <<<HTML
                <th style="padding: 8px 12px; border: 1px solid #e5e7eb; text-align: left;">{$label}</th>

HTML;
            }

            $html .= <<<HTML
            </tr>
        </thead>
        <tbody>
            {% for row in {$dataKey} %}
            <tr>

HTML;

            foreach ($table['columns'] as $column) {
                $key = $column['key'];
                $type = $column['type'] ?? 'text';

                if ($type === 'currency') {
                    $html .= <<<HTML
                <td style="padding: 8px 12px; border: 1px solid #e5e7eb; text-align: right; font-family: monospace;">
                    {% if row.{$key} is iterable %}\${{ row.{$key}.data|default(0)|number_format(2) }}{% else %}\${{ row.{$key}|default(0)|number_format(2) }}{% endif %}
                </td>

HTML;
                } elseif ($type === 'number') {
                    $html .= <<<HTML
                <td style="padding: 8px 12px; border: 1px solid #e5e7eb; text-align: right;">
                    {% if row.{$key} is iterable %}{{ row.{$key}.data|default(0)|number_format }}{% else %}{{ row.{$key}|default(0)|number_format }}{% endif %}
                </td>

HTML;
                } elseif ($type === 'percentage') {
                    $html .= <<<HTML
                <td style="padding: 8px 12px; border: 1px solid #e5e7eb; text-align: right;">
                    {% if row.{$key} is iterable %}{{ row.{$key}.data|default(0)|number_format(1) }}%{% else %}{{ row.{$key}|default(0)|number_format(1) }}%{% endif %}
                </td>

HTML;
                } else {
                    $html .= <<<HTML
                <td style="padding: 8px 12px; border: 1px solid #e5e7eb;">
                    {% if row.{$key} is iterable %}{{ row.{$key}.data|default('') }}{% else %}{{ row.{$key}|default('') }}{% endif %}
                </td>

HTML;
                }
            }

            $html .= <<<'HTML'
            </tr>
            {% endfor %}
        </tbody>
    </table>
    {% else %}
    <p style="color: #6b7280; text-align: center; padding: 24px;">No data available for this section.</p>
    {% endif %}

HTML;
        }

        $html .= <<<'HTML'
</div>
HTML;

        return $html;
    }

    /**
     * Extract available variables from a report structure.
     */
    protected function extractTemplateVariables($structure): array
    {
        $vars = ['report_title', 'description', 'generated_at', 'row_count', 'date', 'store'];

        foreach ($structure->getTables() as $table) {
            $vars[] = $table['data_key'] ?? $table['name'];
        }

        return array_unique($vars);
    }

    /**
     * Toggle enabled status.
     */
    public function toggle(ScheduledReport $scheduledReport): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if ($scheduledReport->store_id !== $store->id) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 403);
        }

        $scheduledReport->update([
            'is_enabled' => ! $scheduledReport->is_enabled,
        ]);

        return response()->json([
            'success' => true,
            'message' => $scheduledReport->is_enabled ? 'Report enabled' : 'Report disabled',
            'is_enabled' => $scheduledReport->is_enabled,
        ]);
    }
}
