<?php

namespace Tests\Feature\Services\AI;

use App\Models\NotificationTemplate;
use App\Models\Store;
use App\Services\AI\AIManager;
use App\Services\AI\Contracts\AIResponse;
use App\Services\AI\EmailTemplateGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EmailTemplateGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_generate_returns_valid_template_structure(): void
    {
        $mockAI = Mockery::mock(AIManager::class);
        $mockAI->shouldReceive('chatWithSystem')
            ->once()
            ->andReturn(new AIResponse(
                content: json_encode([
                    'subject' => 'Daily Report for {{ date }}',
                    'content' => '<h2>Report</h2><p>Date: {{ date }}</p>',
                    'variables' => ['date', 'store'],
                ]),
                provider: 'openai',
                model: 'gpt-4',
                inputTokens: 100,
                outputTokens: 50
            ));

        $generator = new EmailTemplateGenerator($mockAI);

        $result = $generator->generate(
            'Create a daily report email',
            ['date', 'store']
        );

        $this->assertArrayHasKey('subject', $result);
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('variables', $result);
        $this->assertEquals('Daily Report for {{ date }}', $result['subject']);
        $this->assertStringContainsString('{{ date }}', $result['content']);
    }

    public function test_generate_report_template_uses_correct_context(): void
    {
        $mockAI = Mockery::mock(AIManager::class);
        $mockAI->shouldReceive('chatWithSystem')
            ->once()
            ->withArgs(function ($system, $user, $options) {
                // Verify the system prompt contains daily_sales context
                return str_contains($system, 'daily_sales')
                    && str_contains($system, 'SALES REPORT CONTEXT');
            })
            ->andReturn(new AIResponse(
                content: json_encode([
                    'subject' => 'Daily Sales Report',
                    'content' => '<table>{% for row in data %}<tr>{% for cell in row %}<td>{{ cell }}</td>{% endfor %}</tr>{% endfor %}</table>',
                    'variables' => ['date', 'data', 'headings'],
                ]),
                provider: 'anthropic',
                model: 'claude-3',
                inputTokens: 200,
                outputTokens: 100
            ));

        $generator = new EmailTemplateGenerator($mockAI);

        $result = $generator->generateReportTemplate(
            'daily_sales',
            'Create a sales report with a table showing orders'
        );

        $this->assertArrayHasKey('subject', $result);
        $this->assertArrayHasKey('content', $result);
    }

    public function test_create_template_saves_to_database(): void
    {
        $store = Store::factory()->create();

        $mockAI = Mockery::mock(AIManager::class);
        $mockAI->shouldReceive('chatWithSystem')
            ->once()
            ->andReturn(new AIResponse(
                content: json_encode([
                    'subject' => 'Test Subject {{ date }}',
                    'content' => '<p>Test content {{ date }}</p>',
                    'variables' => ['date'],
                ]),
                provider: 'openai',
                model: 'gpt-4',
                inputTokens: 100,
                outputTokens: 50
            ));

        $generator = new EmailTemplateGenerator($mockAI);

        $template = $generator->createTemplate(
            $store,
            'Test Template',
            'test-template',
            'Create a test email template',
            ['date']
        );

        $this->assertInstanceOf(NotificationTemplate::class, $template);
        $this->assertEquals('Test Template', $template->name);
        $this->assertEquals('test-template', $template->slug);
        $this->assertEquals($store->id, $template->store_id);
        $this->assertEquals('Test Subject {{ date }}', $template->subject);
        $this->assertTrue($template->is_enabled);

        $this->assertDatabaseHas('notification_templates', [
            'id' => $template->id,
            'slug' => 'test-template',
            'store_id' => $store->id,
        ]);
    }

    public function test_handles_json_wrapped_in_markdown(): void
    {
        $mockAI = Mockery::mock(AIManager::class);
        $mockAI->shouldReceive('chatWithSystem')
            ->once()
            ->andReturn(new AIResponse(
                content: "Here's the template:\n```json\n".json_encode([
                    'subject' => 'Report',
                    'content' => '<p>Content</p>',
                    'variables' => ['store'],
                ])."\n```",
                provider: 'openai',
                model: 'gpt-4',
                inputTokens: 100,
                outputTokens: 50
            ));

        $generator = new EmailTemplateGenerator($mockAI);

        $result = $generator->generate('Create an email', ['store']);

        $this->assertEquals('Report', $result['subject']);
        $this->assertEquals('<p>Content</p>', $result['content']);
    }

    public function test_throws_exception_on_invalid_response(): void
    {
        $mockAI = Mockery::mock(AIManager::class);
        $mockAI->shouldReceive('chatWithSystem')
            ->once()
            ->andReturn(new AIResponse(
                content: 'This is not valid JSON at all',
                provider: 'openai',
                model: 'gpt-4',
                inputTokens: 100,
                outputTokens: 50
            ));

        $generator = new EmailTemplateGenerator($mockAI);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to parse AI response as JSON');

        $generator->generate('Create an email', ['store']);
    }

    public function test_throws_exception_when_missing_required_keys(): void
    {
        $mockAI = Mockery::mock(AIManager::class);
        $mockAI->shouldReceive('chatWithSystem')
            ->once()
            ->andReturn(new AIResponse(
                content: json_encode([
                    'subject' => 'Has subject but no content',
                ]),
                provider: 'openai',
                model: 'gpt-4',
                inputTokens: 100,
                outputTokens: 50
            ));

        $generator = new EmailTemplateGenerator($mockAI);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing required keys');

        $generator->generate('Create an email', ['store']);
    }
}
