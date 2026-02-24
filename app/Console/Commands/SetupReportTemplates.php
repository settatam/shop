<?php

namespace App\Console\Commands;

use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\NotificationTemplate;
use App\Models\Store;
use App\Services\AI\AIManager;
use App\Services\AI\EmailTemplateGenerator;
use App\Services\StoreContext;
use Illuminate\Console\Command;

class SetupReportTemplates extends Command
{
    protected $signature = 'reports:setup-templates
        {--store= : Store ID to set up templates for (required)}
        {--type= : Report type to set up (daily_sales, daily_buy, or all)}
        {--use-ai : Use AI to generate templates}
        {--recipients= : Comma-separated email addresses for report recipients}
        {--force : Overwrite existing templates}';

    protected $description = 'Set up notification templates and subscriptions for daily reports';

    protected array $defaultTemplates = [
        'daily_sales' => [
            'slug' => 'daily-sales-report',
            'name' => 'Daily Sales Report',
            'activity' => 'reports.daily_sales',
            'subject' => '{{ date }}',
            'variables' => ['date', 'store', 'headings', 'data', 'monthlyData', 'monthly_headings', 'monthOverMonth', 'monthOverMonthHeading'],
            'content' => <<<'HTML'
<h2 style="margin-bottom: 20px;">{{ date }}</h2>

{% if data is not empty %}
<h4 style="margin-top: 25px;">Daily Sales</h4>
<table style="width: 100%; border-collapse: collapse; margin: 15px 0;">
    <thead>
        <tr>
            {% for heading in headings %}
            <th style="padding: 8px 12px; text-align: left; border: 1px solid #ddd; background: #f9f7f6; font-size: 12px;">{{ heading }}</th>
            {% endfor %}
        </tr>
    </thead>
    <tbody>
        {% for row in data %}
        <tr>
            {% for cell in row %}
            <td style="padding: 8px 12px; text-align: left; border: 1px solid #ddd; font-size: 12px;">{{ cell }}</td>
            {% endfor %}
        </tr>
        {% endfor %}
    </tbody>
</table>
{% else %}
<p style="color: #666;">No sales for this date.</p>
{% endif %}

{% if monthlyData is not empty %}
<h4 style="margin-top: 25px;">Month to Date</h4>
<table style="width: 100%; border-collapse: collapse; margin: 15px 0;">
    <thead>
        <tr>
            {% for heading in monthly_headings %}
            <th style="padding: 8px 12px; text-align: left; border: 1px solid #ddd; background: #f9f7f6; font-size: 12px;">{{ heading }}</th>
            {% endfor %}
        </tr>
    </thead>
    <tbody>
        {% for row in monthlyData %}
        <tr>
            {% for cell in row %}
            <td style="padding: 8px 12px; text-align: left; border: 1px solid #ddd; font-size: 12px;">{{ cell }}</td>
            {% endfor %}
        </tr>
        {% endfor %}
    </tbody>
</table>
{% endif %}

{% if monthOverMonth is not empty %}
<h4 style="margin-top: 25px;">Month Over Month</h4>
<table style="width: 100%; border-collapse: collapse; margin: 15px 0;">
    <thead>
        <tr>
            {% for heading in monthOverMonthHeading %}
            <th style="padding: 8px 12px; text-align: left; border: 1px solid #ddd; background: #f9f7f6; font-size: 12px;">{{ heading }}</th>
            {% endfor %}
        </tr>
    </thead>
    <tbody>
        {% for row in monthOverMonth %}
        <tr>
            {% for cell in row %}
            <td style="padding: 8px 12px; text-align: left; border: 1px solid #ddd; font-size: 12px;">{{ cell }}</td>
            {% endfor %}
        </tr>
        {% endfor %}
    </tbody>
</table>
{% endif %}
HTML,
        ],
        'daily_buy' => [
            'slug' => 'daily-buy-report',
            'name' => 'Daily Buy Report',
            'activity' => 'reports.daily_buy',
            'subject' => '{{ date }}',
            'variables' => ['date', 'store', 'headings', 'data', 'monthlyData', 'monthly_headings', 'monthOverMonth', 'monthOverMonthHeading'],
            'content' => <<<'HTML'
<h2 style="margin-bottom: 20px;">{{ date }}</h2>

{% if data is not empty %}
<h4 style="margin-top: 25px;">Daily Buy Transactions</h4>
<table style="width: 100%; border-collapse: collapse; margin: 15px 0;">
    <thead>
        <tr>
            {% for heading in headings %}
            <th style="padding: 8px 12px; text-align: left; border: 1px solid #ddd; background: #f9f7f6; font-size: 12px;">{{ heading }}</th>
            {% endfor %}
        </tr>
    </thead>
    <tbody>
        {% for row in data %}
        <tr>
            {% for cell in row %}
            <td style="padding: 8px 12px; text-align: left; border: 1px solid #ddd; font-size: 12px;">{{ cell }}</td>
            {% endfor %}
        </tr>
        {% endfor %}
    </tbody>
</table>
{% else %}
<p style="color: #666;">No buy transactions for this date.</p>
{% endif %}

{% if monthlyData is not empty %}
<h4 style="margin-top: 25px;">Month to Date</h4>
<table style="width: 100%; border-collapse: collapse; margin: 15px 0;">
    <thead>
        <tr>
            {% for heading in monthly_headings %}
            <th style="padding: 8px 12px; text-align: left; border: 1px solid #ddd; background: #f9f7f6; font-size: 12px;">{{ heading }}</th>
            {% endfor %}
        </tr>
    </thead>
    <tbody>
        {% for row in monthlyData %}
        <tr>
            {% for cell in row %}
            <td style="padding: 8px 12px; text-align: left; border: 1px solid #ddd; font-size: 12px;">{{ cell }}</td>
            {% endfor %}
        </tr>
        {% endfor %}
    </tbody>
</table>
{% endif %}

{% if monthOverMonth is not empty %}
<h4 style="margin-top: 25px;">Month Over Month</h4>
<table style="width: 100%; border-collapse: collapse; margin: 15px 0;">
    <thead>
        <tr>
            {% for heading in monthOverMonthHeading %}
            <th style="padding: 8px 12px; text-align: left; border: 1px solid #ddd; background: #f9f7f6; font-size: 12px;">{{ heading }}</th>
            {% endfor %}
        </tr>
    </thead>
    <tbody>
        {% for row in monthOverMonth %}
        <tr>
            {% for cell in row %}
            <td style="padding: 8px 12px; text-align: left; border: 1px solid #ddd; font-size: 12px;">{{ cell }}</td>
            {% endfor %}
        </tr>
        {% endfor %}
    </tbody>
</table>
{% endif %}
HTML,
        ],
    ];

    public function handle(): int
    {
        $storeId = $this->option('store');

        if (! $storeId) {
            $this->error('Store ID is required. Use --store=ID');

            return self::FAILURE;
        }

        $store = Store::find($storeId);

        if (! $store) {
            $this->error("Store {$storeId} not found.");

            return self::FAILURE;
        }

        $types = $this->getTypes();
        $recipients = $this->getRecipients();
        $force = $this->option('force');
        $useAI = $this->option('use-ai');

        $this->info("Setting up report templates for store: {$store->name} (ID: {$store->id})");

        if ($useAI) {
            $this->warn('Using AI to generate templates...');
        }

        foreach ($types as $type) {
            if (! isset($this->defaultTemplates[$type])) {
                $this->warn("Unknown report type: {$type}");

                continue;
            }

            $templateConfig = $this->defaultTemplates[$type];

            // Check if template already exists
            $existingTemplate = NotificationTemplate::where('store_id', $storeId)
                ->where('slug', $templateConfig['slug'])
                ->first();

            if ($existingTemplate && ! $force) {
                $this->line("  [{$type}] Template already exists (slug: {$templateConfig['slug']}), skipping. Use --force to overwrite.");

                continue;
            }

            // Generate content using AI if requested
            if ($useAI) {
                $this->line("  [{$type}] Generating template with AI...");
                try {
                    $generated = $this->generateWithAI($type, $templateConfig);
                    $templateConfig['subject'] = $generated['subject'];
                    $templateConfig['content'] = $generated['content'];
                } catch (\Exception $e) {
                    $this->warn("    AI generation failed: {$e->getMessage()}. Using default template.");
                }
            }

            // Create or update template
            $template = NotificationTemplate::updateOrCreate(
                [
                    'store_id' => $storeId,
                    'slug' => $templateConfig['slug'],
                    'channel' => NotificationChannel::TYPE_EMAIL,
                ],
                [
                    'name' => $templateConfig['name'],
                    'subject' => $templateConfig['subject'],
                    'content' => $templateConfig['content'],
                    'available_variables' => $templateConfig['variables'],
                    'category' => 'reports',
                    'is_system' => false,
                    'is_enabled' => true,
                ]
            );

            $this->line("  [{$type}] Template created/updated: {$template->name}");

            // Create subscription if recipients provided
            if (! empty($recipients)) {
                $subscriptionRecipients = array_map(function ($email) {
                    return ['type' => 'custom', 'value' => $email];
                }, $recipients);

                $subscription = NotificationSubscription::updateOrCreate(
                    [
                        'store_id' => $storeId,
                        'activity' => $templateConfig['activity'],
                    ],
                    [
                        'notification_template_id' => $template->id,
                        'name' => $templateConfig['name'],
                        'description' => "Automated daily {$type} report",
                        'recipients' => $subscriptionRecipients,
                        'schedule_type' => NotificationSubscription::SCHEDULE_SCHEDULED,
                        'is_enabled' => true,
                    ]
                );

                $this->line("  [{$type}] Subscription created/updated with ".count($recipients).' recipient(s)');
            }
        }

        $this->newLine();
        $this->info('Templates setup complete!');
        $this->line('');
        $this->line('To test, run:');
        $this->line("  php artisan reports:send-legacy-daily --store={$storeId} --dry-run");
        $this->line('');
        $this->line('To send to a test email:');
        $this->line("  php artisan reports:send-legacy-daily --store={$storeId} --test-email=your@email.com");

        return self::SUCCESS;
    }

    protected function getTypes(): array
    {
        $typeOption = $this->option('type');

        if ($typeOption && $typeOption !== 'all') {
            return array_map('trim', explode(',', $typeOption));
        }

        return array_keys($this->defaultTemplates);
    }

    protected function getRecipients(): array
    {
        $recipientOption = $this->option('recipients');

        if (! $recipientOption) {
            return [];
        }

        $emails = array_map('trim', explode(',', $recipientOption));

        return array_filter($emails, fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL));
    }

    protected function generateWithAI(string $type, array $templateConfig): array
    {
        $aiManager = app(AIManager::class);
        $storeContext = app(StoreContext::class);

        $generator = new EmailTemplateGenerator($aiManager);

        $description = match ($type) {
            'daily_sales' => 'Create a daily sales report email that shows a table of individual sales for the day with order number, customer name, total, and status. Include a month-to-date summary table and a month-over-month comparison. Use professional styling with light gray header backgrounds.',
            'daily_buy' => 'Create a daily buy/purchase report email for a gold and jewelry buying business. Show a table of customer trade-in transactions with transaction number, customer name, amount bought, profit, and payment type. Include month-to-date and month-over-month summaries.',
            default => "Create a daily {$type} report email with professional table formatting.",
        };

        return $generator->generateReportTemplate($type, $description, []);
    }
}
