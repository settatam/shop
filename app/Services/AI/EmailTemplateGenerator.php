<?php

namespace App\Services\AI;

use App\Models\NotificationChannel;
use App\Models\NotificationTemplate;
use App\Models\Store;
use App\Services\AI\Contracts\AIResponse;

class EmailTemplateGenerator
{
    protected AIManager $ai;

    protected array $contextVariables = [];

    public function __construct(AIManager $ai)
    {
        $this->ai = $ai;
    }

    /**
     * Generate an email template from a natural language description.
     *
     * @param  string  $description  What the user wants the email to contain
     * @param  array  $availableVariables  Variables that can be used in the template (e.g., ['order', 'customer', 'store'])
     * @param  array  $options  Additional options for generation
     *                          - 'sample_data': array - Actual sample data showing the structure of variables
     *                          - 'style': string - 'professional', 'casual', 'formal'
     *                          - 'tone': string - e.g., 'friendly but professional'
     *                          - 'provider': string - AI provider to use
     */
    public function generate(string $description, array $availableVariables = [], array $options = []): array
    {
        $sampleData = $options['sample_data'] ?? null;
        $contextInfo = $this->buildContextInfo($availableVariables, $sampleData);

        $systemPrompt = $this->buildSystemPrompt($contextInfo, $options);
        $userPrompt = $this->buildUserPrompt($description, $availableVariables, $sampleData);

        $response = $this->ai->chatWithSystem($systemPrompt, $userPrompt, [
            'feature' => 'email_template_generator',
            'provider' => $options['provider'] ?? null,
        ]);

        return $this->parseResponse($response, $availableVariables);
    }

    /**
     * Generate a report email template.
     */
    public function generateReportTemplate(
        string $reportType,
        string $description,
        array $sampleData = [],
        array $options = []
    ): array {
        $reportVariables = $this->getReportVariables($reportType);

        $systemPrompt = $this->buildReportSystemPrompt($reportType);
        $userPrompt = $this->buildReportUserPrompt($description, $reportVariables, $sampleData);

        $response = $this->ai->chatWithSystem($systemPrompt, $userPrompt, [
            'feature' => 'report_template_generator',
            'provider' => $options['provider'] ?? null,
        ]);

        return $this->parseResponse($response, $reportVariables);
    }

    /**
     * Create and save a notification template from a description.
     */
    public function createTemplate(
        Store $store,
        string $name,
        string $slug,
        string $description,
        array $availableVariables = [],
        array $options = []
    ): NotificationTemplate {
        $generated = $this->generate($description, $availableVariables, $options);

        return NotificationTemplate::create([
            'store_id' => $store->id,
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'channel' => NotificationChannel::TYPE_EMAIL,
            'subject' => $generated['subject'],
            'content' => $generated['content'],
            'available_variables' => $generated['variables'],
            'category' => $options['category'] ?? 'reports',
            'is_system' => false,
            'is_enabled' => true,
        ]);
    }

    protected function buildSystemPrompt(string $contextInfo, array $options = []): string
    {
        $style = $options['style'] ?? 'professional';
        $tone = $options['tone'] ?? 'friendly but professional';

        return <<<PROMPT
You are an expert email template designer. Generate HTML email templates using Twig templating syntax.

IMPORTANT RULES:
1. Output ONLY valid JSON with these exact keys: "subject", "content", "variables"
2. The "subject" should use Twig syntax for variables: {{ variable.field }}
3. The "content" must be valid HTML with Twig templating
4. Use proper Twig syntax: {{ variable }}, {% for item in items %}, {% if condition %}
5. Available Twig filters: |money (formats currency), |date_format('m-d-Y') (formats dates)
6. Keep emails clean, readable, and mobile-friendly
7. Use inline CSS styles
8. Style: {$style}, Tone: {$tone}

AVAILABLE CONTEXT:
{$contextInfo}

EXAMPLE OUTPUT:
{
    "subject": "Daily Sales Report for {{ date }}",
    "content": "<h2>Daily Sales Report</h2><p>Here is your report for {{ date }}.</p><table style=\"width:100%;border-collapse:collapse\"><thead><tr><th>Order</th><th>Total</th></tr></thead><tbody>{% for row in data %}<tr><td>{{ row[0] }}</td><td>{{ row[1] }}</td></tr>{% endfor %}</tbody></table>",
    "variables": ["date", "data", "store"]
}
PROMPT;
    }

    protected function buildReportSystemPrompt(string $reportType): string
    {
        $reportContext = match ($reportType) {
            'daily_sales' => $this->getSalesReportContext(),
            'daily_buy' => $this->getBuyReportContext(),
            'daily_memos' => $this->getMemosReportContext(),
            'daily_repairs' => $this->getRepairsReportContext(),
            default => $this->getGenericReportContext(),
        };

        return <<<PROMPT
You are an expert email template designer specializing in business reports. Generate HTML email templates using Twig templating syntax.

REPORT TYPE: {$reportType}

IMPORTANT RULES:
1. Output ONLY valid JSON with these exact keys: "subject", "content", "variables"
2. The "subject" should include the report date using: {{ date }}
3. The "content" must be valid HTML with Twig templating
4. Use proper Twig syntax: {{ variable }}, {% for item in items %}, {% if condition %}
5. Available Twig filters: |money (formats currency), |date_format('m-d-Y') (formats dates)
6. Create professional, easy-to-read reports with proper tables
7. Use inline CSS styles (border-collapse, padding, etc.)
8. Include summaries and totals where appropriate

{$reportContext}

OUTPUT FORMAT - RESPOND WITH ONLY THIS JSON:
{
    "subject": "...",
    "content": "...",
    "variables": [...]
}
PROMPT;
    }

    protected function buildUserPrompt(string $description, array $availableVariables, ?array $sampleData = null): string
    {
        $varsStr = implode(', ', $availableVariables);

        $sampleDataStr = '';
        if ($sampleData) {
            $sampleDataStr = "\n\nSAMPLE DATA (use this exact structure in your template):\n".
                json_encode($sampleData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        return <<<PROMPT
Create an email template for the following:

DESCRIPTION: {$description}

AVAILABLE VARIABLES: {$varsStr}{$sampleDataStr}

Generate the template JSON now. Remember to output ONLY the JSON object.
PROMPT;
    }

    protected function buildReportUserPrompt(string $description, array $reportVariables, array $sampleData): string
    {
        $varsStr = '';
        foreach ($reportVariables as $var => $desc) {
            $varsStr .= "- {$var}: {$desc}\n";
        }

        $sampleStr = '';
        if (! empty($sampleData)) {
            $sampleStr = "SAMPLE DATA STRUCTURE:\n".json_encode($sampleData, JSON_PRETTY_PRINT);
        }

        return <<<PROMPT
Create a report email template:

DESCRIPTION: {$description}

AVAILABLE VARIABLES:
{$varsStr}

{$sampleStr}

Generate the template JSON now. Remember to output ONLY the JSON object.
PROMPT;
    }

    protected function buildContextInfo(array $variables, ?array $sampleData = null): string
    {
        $info = "AVAILABLE VARIABLES AND THEIR FIELDS:\n";

        foreach ($variables as $variable) {
            $fields = $this->getVariableFields($variable);
            if (! empty($fields)) {
                $info .= "\n{$variable}:\n";
                foreach ($fields as $field => $type) {
                    $info .= "  - {$field} ({$type})\n";
                }
            }
        }

        // If sample data is provided, include the actual structure
        if ($sampleData) {
            $info .= "\n\nACTUAL DATA STRUCTURE (match this exactly):\n";
            $info .= $this->describeDataStructure($sampleData);
        }

        return $info;
    }

    /**
     * Describe the structure of sample data for the AI.
     */
    protected function describeDataStructure(array $data, int $depth = 0): string
    {
        $indent = str_repeat('  ', $depth);
        $description = '';

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if ($this->isSequentialArray($value)) {
                    // It's a list/array
                    $firstItem = $value[0] ?? null;
                    if (is_array($firstItem)) {
                        if ($this->isSequentialArray($firstItem)) {
                            // Array of arrays (like data rows)
                            $description .= "{$indent}- {$key}: array of arrays, each inner array has ".count($firstItem)." elements\n";
                            $description .= "{$indent}  Example row: ".json_encode($firstItem)."\n";
                        } else {
                            // Array of objects
                            $description .= "{$indent}- {$key}: array of objects with keys: ".implode(', ', array_keys($firstItem))."\n";
                        }
                    } else {
                        // Array of scalars
                        $description .= "{$indent}- {$key}: array of ".gettype($firstItem)."s\n";
                        $description .= "{$indent}  Example: ".json_encode(array_slice($value, 0, 3))."\n";
                    }
                } else {
                    // It's an object/associative array
                    $description .= "{$indent}- {$key}: object with fields:\n";
                    $description .= $this->describeDataStructure($value, $depth + 1);
                }
            } else {
                $type = gettype($value);
                $example = is_string($value) && strlen($value) > 50
                    ? substr($value, 0, 50).'...'
                    : $value;
                $description .= "{$indent}- {$key}: {$type} (e.g., ".json_encode($example).")\n";
            }
        }

        return $description;
    }

    /**
     * Check if an array is sequential (list) vs associative (object).
     */
    protected function isSequentialArray(array $arr): bool
    {
        if (empty($arr)) {
            return true;
        }

        return array_keys($arr) === range(0, count($arr) - 1);
    }

    protected function getVariableFields(string $variable): array
    {
        return match ($variable) {
            'store' => [
                'name' => 'string',
                'email' => 'string',
                'phone' => 'string',
                'address.street' => 'string',
                'address.city' => 'string',
                'address.state' => 'string',
                'address.zip' => 'string',
            ],
            'customer' => [
                'name' => 'string',
                'email' => 'string',
                'phone' => 'string',
            ],
            'order' => [
                'number' => 'string',
                'total' => 'decimal',
                'subtotal' => 'decimal',
                'tax' => 'decimal',
                'status' => 'string',
                'created_at' => 'datetime',
            ],
            'product' => [
                'title' => 'string',
                'sku' => 'string',
                'price' => 'decimal',
                'description' => 'string',
            ],
            'transaction' => [
                'id' => 'integer',
                'bought' => 'decimal',
                'profit' => 'decimal',
                'payment_type' => 'string',
                'customer_name' => 'string',
            ],
            default => [],
        };
    }

    protected function getReportVariables(string $reportType): array
    {
        return match ($reportType) {
            'daily_sales' => [
                'date' => 'The report date label (e.g., "REB - Daily Sales Report for 01-15-2025")',
                'store' => 'Store object with name, address, phone, email',
                'headings' => 'Array of column headers for the daily data table',
                'data' => 'Array of rows for daily sales data (each row is an array)',
                'monthlyData' => 'Array of monthly summary rows',
                'monthly_headings' => 'Column headers for monthly data',
                'monthOverMonth' => 'Month comparison data array',
                'monthOverMonthHeading' => 'Column headers for month over month table',
            ],
            'daily_buy' => [
                'date' => 'The report date label',
                'store' => 'Store object with name, address, phone, email',
                'headings' => 'Array of column headers (Date, Transaction #, Customer, Bought, Profit, Payment Type)',
                'data' => 'Array of daily buy transaction rows',
                'monthlyData' => 'Array of monthly summary rows',
                'monthly_headings' => 'Column headers for monthly summary',
                'monthOverMonth' => 'Month comparison data',
                'monthOverMonthHeading' => 'Headers for month comparison',
            ],
            default => [
                'date' => 'Report date label',
                'store' => 'Store object',
                'headings' => 'Column headers array',
                'data' => 'Data rows array',
            ],
        };
    }

    protected function getSalesReportContext(): string
    {
        return <<<'CONTEXT'
SALES REPORT CONTEXT:
- This is a daily sales summary sent to management
- Shows individual sales for the day with order details
- Includes month-to-date summary
- Includes month-over-month comparison
- Data arrays contain: [Date, Order #, Customer, Total, Status]
- Monthly data format: [Month, Sales #, Items Sold, Total Sales]
CONTEXT;
    }

    protected function getBuyReportContext(): string
    {
        return <<<'CONTEXT'
BUY REPORT CONTEXT:
- This is a daily buy/purchase summary for gold/jewelry buying business
- Shows customer trade-ins and purchases from customers
- Includes what was bought, profit margin, payment type
- Data format: [Date, Transaction #, Customer, Bought Amount, Profit, Payment Type]
- Sent daily to track buying activity
CONTEXT;
    }

    protected function getMemosReportContext(): string
    {
        return <<<'CONTEXT'
MEMOS REPORT CONTEXT:
- Shows consignment/memo items activity for the day
- Includes items sent out and received back
- Tracks memo status and values
CONTEXT;
    }

    protected function getRepairsReportContext(): string
    {
        return <<<'CONTEXT'
REPAIRS REPORT CONTEXT:
- Shows repair job activity for the day
- Includes new repairs, completed repairs, status updates
- Tracks repair costs and customer information
CONTEXT;
    }

    protected function getGenericReportContext(): string
    {
        return <<<'CONTEXT'
REPORT CONTEXT:
- Generic business report template
- Include summary statistics and detailed data table
- Professional formatting with clear sections
CONTEXT;
    }

    protected function parseResponse(AIResponse $response, array $expectedVariables): array
    {
        $content = $response->content;

        // Try to extract JSON from the response
        $json = $this->extractJson($content);

        if ($json === null) {
            throw new \RuntimeException('Failed to parse AI response as JSON: '.$content);
        }

        // Validate required keys
        if (! isset($json['subject']) || ! isset($json['content'])) {
            throw new \RuntimeException('AI response missing required keys (subject, content)');
        }

        return [
            'subject' => $json['subject'],
            'content' => $json['content'],
            'variables' => $json['variables'] ?? $expectedVariables,
            'provider' => $response->provider,
            'model' => $response->model,
            'tokens_used' => $response->totalTokens(),
        ];
    }

    protected function extractJson(string $content): ?array
    {
        // First try direct parsing
        $json = json_decode($content, true);
        if ($json !== null) {
            return $json;
        }

        // Try to find JSON in the response (sometimes wrapped in markdown code blocks)
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/i', $content, $matches)) {
            $json = json_decode($matches[1], true);
            if ($json !== null) {
                return $json;
            }
        }

        // Try to find any JSON object
        if (preg_match('/\{[\s\S]*"subject"[\s\S]*"content"[\s\S]*\}/i', $content, $matches)) {
            $json = json_decode($matches[0], true);
            if ($json !== null) {
                return $json;
            }
        }

        return null;
    }
}
