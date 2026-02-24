<?php

namespace App\Services\Reports;

use App\Models\NotificationTemplate;
use App\Services\AI\AIManager;

/**
 * AI-powered generator that:
 * 1. Creates report structures from natural language
 * 2. Modifies existing structures based on user requests
 * 3. Generates Twig templates from structures
 */
class ReportStructureGenerator
{
    public function __construct(
        protected AIManager $ai,
        protected ReportFieldRegistry $fieldRegistry,
    ) {}

    /**
     * Create a report structure from a natural language description.
     *
     * Example: "Create a daily sales report with order number, customer name, total, and date"
     */
    public function createStructure(string $description, array $options = []): ReportStructure
    {
        $systemPrompt = $this->buildStructureSystemPrompt();
        $userPrompt = $this->buildStructureUserPrompt($description, $options);

        $response = $this->ai->chatWithSystem($systemPrompt, $userPrompt, [
            'feature' => 'report_structure_generator',
            'provider' => $options['provider'] ?? null,
        ]);

        $json = $this->extractJson($response->content);

        if (! $json) {
            throw new \RuntimeException('Failed to parse AI response as JSON');
        }

        return ReportStructure::fromArray($json);
    }

    /**
     * Modify an existing structure based on a natural language request.
     *
     * Examples:
     * - "Remove the profit column"
     * - "Add customer phone to the table"
     * - "Change the title to Daily Buy Summary"
     */
    public function modifyStructure(ReportStructure $structure, string $modification, array $options = []): ReportStructure
    {
        $systemPrompt = $this->buildModifySystemPrompt();
        $userPrompt = $this->buildModifyUserPrompt($structure, $modification);

        $response = $this->ai->chatWithSystem($systemPrompt, $userPrompt, [
            'feature' => 'report_structure_modifier',
            'provider' => $options['provider'] ?? null,
        ]);

        $json = $this->extractJson($response->content);

        if (! $json) {
            throw new \RuntimeException('Failed to parse AI response as JSON');
        }

        return ReportStructure::fromArray($json);
    }

    /**
     * Generate a Twig template from a report structure.
     */
    public function generateTemplate(ReportStructure $structure, array $options = []): array
    {
        $systemPrompt = $this->buildTemplateSystemPrompt();
        $userPrompt = $this->buildTemplateUserPrompt($structure);

        $response = $this->ai->chatWithSystem($systemPrompt, $userPrompt, [
            'feature' => 'report_template_generator',
            'provider' => $options['provider'] ?? null,
        ]);

        $json = $this->extractJson($response->content);

        if (! $json || ! isset($json['subject']) || ! isset($json['content'])) {
            throw new \RuntimeException('Failed to generate valid template');
        }

        return [
            'subject' => $json['subject'],
            'content' => $json['content'],
            'variables' => $this->extractVariables($structure),
        ];
    }

    /**
     * Create structure AND generate template in one call.
     */
    public function createReportTemplate(
        string $description,
        int $storeId,
        string $name,
        string $slug,
        array $options = []
    ): NotificationTemplate {
        // Step 1: Create structure from description
        $structure = $this->createStructure($description, $options);

        // Step 2: Generate Twig template from structure
        $template = $this->generateTemplate($structure, $options);

        // Step 3: Save to database
        return NotificationTemplate::create([
            'store_id' => $storeId,
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'channel' => 'email',
            'subject' => $template['subject'],
            'content' => $template['content'],
            'structure' => $structure->toArray(),
            'template_type' => 'structured',
            'available_variables' => $template['variables'],
            'category' => $options['category'] ?? 'reports',
            'is_enabled' => true,
        ]);
    }

    protected function buildStructureSystemPrompt(): string
    {
        $fieldsSummary = $this->fieldRegistry->getAISummary();

        return <<<PROMPT
You are a report structure generator. Create JSON structures for business reports based on natural language descriptions.

{$fieldsSummary}

OUTPUT FORMAT - Return ONLY valid JSON:
{
    "title": "Report Title (use {{ date }} for dynamic date)",
    "subtitle": "Optional subtitle",
    "logo": null,
    "tables": [
        {
            "name": "table_identifier",
            "heading": "Table Display Heading",
            "data_key": "data",
            "columns": [
                {"key": "field_key", "label": "Display Label", "type": "string|currency|date|number"}
            ]
        }
    ],
    "summary_blocks": [
        {
            "name": "totals",
            "heading": "Summary",
            "items": [
                {"label": "Total Sales", "key": "total_sales", "type": "currency"}
            ]
        }
    ],
    "footer": "Optional footer text",
    "metadata": {"report_type": "daily_sales"}
}

RULES:
1. Match user's requested columns to the available fields
2. Use appropriate types (currency for money, date for dates, etc.)
3. data_key is the variable name that will contain the table rows
4. For multiple tables, use different data_keys (data, monthlyData, etc.)
5. Column keys should match what will be in the data arrays
PROMPT;
    }

    protected function buildStructureUserPrompt(string $description, array $options = []): string
    {
        $reportType = $options['report_type'] ?? 'custom';

        return <<<PROMPT
Create a report structure for:

DESCRIPTION: {$description}
REPORT TYPE: {$reportType}

Generate the JSON structure now. Output ONLY the JSON.
PROMPT;
    }

    protected function buildModifySystemPrompt(): string
    {
        $fieldsSummary = $this->fieldRegistry->getAISummary();

        return <<<PROMPT
You are a report structure editor. Modify existing report structures based on user requests.

{$fieldsSummary}

You will receive an existing structure and a modification request.
Apply the requested changes and return the complete updated structure.

OUTPUT FORMAT - Return ONLY the complete modified JSON structure.

MODIFICATION TYPES:
- Add/remove columns: "add customer phone", "remove the profit column"
- Add/remove tables: "add a monthly summary table"
- Change labels: "rename 'Bought' to 'Purchase Amount'"
- Change title/subtitle: "change the title to..."
- Reorder columns: "move date to the first column"
PROMPT;
    }

    protected function buildModifyUserPrompt(ReportStructure $structure, string $modification): string
    {
        $currentJson = $structure->toJson();

        return <<<PROMPT
CURRENT STRUCTURE:
{$currentJson}

MODIFICATION REQUEST: {$modification}

Apply the modification and return the complete updated JSON structure.
PROMPT;
    }

    protected function buildTemplateSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a Twig email template generator. Create HTML email templates from report structures.

OUTPUT FORMAT - Return ONLY valid JSON:
{
    "subject": "Email subject with {{ variables }}",
    "content": "<html>...</html>"
}

TWIG RULES:
1. Use {{ variable }} for values, {% for %} for loops, {% if %} for conditions
2. For table data: {% for row in data %}...{% endfor %}
3. Access row values by index: {{ row[0] }}, {{ row[1] }}, etc.
4. Available filters: |money (currency), |date_format('m-d-Y')

STYLING RULES:
1. Use inline CSS styles
2. Tables: border-collapse: collapse; width: 100%
3. Headers: background: #f9f7f6; padding: 8px 12px; border: 1px solid #ddd
4. Cells: padding: 8px 12px; border: 1px solid #ddd
5. Section headings: margin-top: 25px; color: #333
6. Professional, clean design
PROMPT;
    }

    protected function buildTemplateUserPrompt(ReportStructure $structure): string
    {
        $structureDesc = $structure->describeForAI();
        $sampleData = json_encode($structure->generateSampleData(), JSON_PRETTY_PRINT);

        return <<<PROMPT
Generate a Twig email template for this report structure:

{$structureDesc}

SAMPLE DATA FORMAT:
{$sampleData}

Create a professional HTML email template. Output ONLY the JSON with subject and content.
PROMPT;
    }

    protected function extractVariables(ReportStructure $structure): array
    {
        $vars = ['date', 'store'];

        foreach ($structure->getTables() as $table) {
            $vars[] = $table['data_key'];
            $vars[] = $table['data_key'].'_headings';
        }

        foreach ($structure->getSummaryBlocks() as $block) {
            foreach ($block['items'] as $item) {
                $vars[] = $item['key'];
            }
        }

        return array_unique($vars);
    }

    protected function extractJson(string $content): ?array
    {
        // Try direct parse
        $json = json_decode($content, true);
        if ($json !== null) {
            return $json;
        }

        // Try extracting from markdown code block
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/i', $content, $matches)) {
            $json = json_decode($matches[1], true);
            if ($json !== null) {
                return $json;
            }
        }

        // Try to find JSON object
        if (preg_match('/\{[\s\S]*\}/i', $content, $matches)) {
            $json = json_decode($matches[0], true);
            if ($json !== null) {
                return $json;
            }
        }

        return null;
    }
}
