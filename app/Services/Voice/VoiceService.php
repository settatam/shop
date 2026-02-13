<?php

namespace App\Services\Voice;

use App\Services\Chat\ChatToolExecutor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VoiceService
{
    public function __construct(
        protected SpeechToText $speechToText,
        protected TextToSpeech $textToSpeech,
        protected ChatToolExecutor $toolExecutor
    ) {}

    /**
     * Process a voice query from audio input.
     */
    public function processVoiceQuery(UploadedFile $audio, int $storeId): VoiceResponse
    {
        // Step 1: Transcribe audio to text
        $transcription = $this->speechToText->transcribe($audio);

        if (! $transcription->success) {
            return VoiceResponse::failure($transcription->error ?? 'Transcription failed');
        }

        $transcript = trim($transcription->text);

        if (empty($transcript)) {
            return VoiceResponse::failure('Could not understand the audio');
        }

        // Step 2: Process the query using Claude with tools
        $response = $this->processWithClaude($transcript, $storeId);

        if (! $response['success']) {
            return VoiceResponse::failure($response['error'] ?? 'Failed to process query');
        }

        $responseText = $response['text'];

        // Step 3: Convert response to speech
        $synthesis = $this->textToSpeech->synthesize($responseText);

        return new VoiceResponse(
            success: true,
            transcript: $transcript,
            response: $responseText,
            audioUrl: $synthesis->success ? $synthesis->url : null
        );
    }

    /**
     * Process a text query (for testing without audio).
     */
    public function processTextQuery(string $query, int $storeId): VoiceResponse
    {
        $response = $this->processWithClaude($query, $storeId);

        if (! $response['success']) {
            return VoiceResponse::failure($response['error'] ?? 'Failed to process query');
        }

        return new VoiceResponse(
            success: true,
            transcript: $query,
            response: $response['text']
        );
    }

    /**
     * Process query with Claude, using available tools.
     *
     * @return array{success: bool, text?: string, error?: string}
     */
    protected function processWithClaude(string $query, int $storeId): array
    {
        $apiKey = config('services.anthropic.api_key');

        if (! $apiKey) {
            return ['success' => false, 'error' => 'Anthropic API key not configured'];
        }

        $systemPrompt = $this->buildSystemPrompt();
        $tools = $this->toolExecutor->getDefinitions();

        try {
            $messages = [
                ['role' => 'user', 'content' => $query],
            ];

            // Initial request
            $response = $this->callClaude($apiKey, $systemPrompt, $messages, $tools);

            if (! $response['success']) {
                return $response;
            }

            $content = $response['content'];
            $stopReason = $response['stop_reason'];

            // Handle tool use
            $maxIterations = 5;
            $iteration = 0;

            while ($stopReason === 'tool_use' && $iteration < $maxIterations) {
                $iteration++;

                // Extract tool calls
                $toolCalls = array_filter($content, fn ($block) => ($block['type'] ?? '') === 'tool_use');

                if (empty($toolCalls)) {
                    break;
                }

                // Add assistant message
                $messages[] = ['role' => 'assistant', 'content' => $content];

                // Execute tools and build results
                $toolResults = [];
                foreach ($toolCalls as $toolCall) {
                    $toolName = $toolCall['name'];
                    $toolInput = $toolCall['input'] ?? [];
                    $toolId = $toolCall['id'];

                    $result = $this->toolExecutor->execute($toolName, $toolInput, $storeId);

                    $toolResults[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $toolId,
                        'content' => json_encode($result),
                    ];
                }

                // Add tool results
                $messages[] = ['role' => 'user', 'content' => $toolResults];

                // Continue conversation
                $response = $this->callClaude($apiKey, $systemPrompt, $messages, $tools);

                if (! $response['success']) {
                    return $response;
                }

                $content = $response['content'];
                $stopReason = $response['stop_reason'];
            }

            // Extract final text response
            $textBlocks = array_filter($content, fn ($block) => ($block['type'] ?? '') === 'text');
            $text = implode("\n", array_map(fn ($block) => $block['text'] ?? '', $textBlocks));

            return ['success' => true, 'text' => trim($text)];
        } catch (\Throwable $e) {
            Log::error('Claude API error in voice service', [
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Call Claude API.
     *
     * @return array{success: bool, content?: array, stop_reason?: string, error?: string}
     */
    protected function callClaude(string $apiKey, string $systemPrompt, array $messages, array $tools): array
    {
        // Fix tool schemas - empty arrays must be objects for Anthropic API
        $tools = $this->normalizeToolSchemas($tools);

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
        ])
            ->timeout(60)
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model', 'claude-sonnet-4-20250514'),
                'max_tokens' => 1024,
                'system' => $systemPrompt,
                'messages' => $messages,
                'tools' => $tools,
            ]);

        if ($response->failed()) {
            Log::error('Claude API request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return ['success' => false, 'error' => 'AI request failed: '.$response->status()];
        }

        $data = $response->json();

        return [
            'success' => true,
            'content' => $data['content'] ?? [],
            'stop_reason' => $data['stop_reason'] ?? 'end_turn',
        ];
    }

    protected function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
You are the Store Manager - an AI assistant for Shopmata, a point-of-sale system for pawn shops and jewelry stores. You're like having an experienced store manager on call 24/7.

YOUR ROLE:
- Give verbal briefings and reports
- Help with pricing and buy offers
- Look up customer history
- Calculate metal values instantly
- Coach through negotiations
- Handle opening and closing procedures

SPEAKING STYLE:
- Talk like a real person, not a robot
- Use conversational numbers: "about thirty-two hundred" not "$3,247.83"
- Be concise - 30 seconds to 1 minute max
- Lead with the most important info
- Give actionable insights, not just data

MORNING BRIEFING ("morning briefing", "open the store", "what do I need to know"):
"Good morning! Yesterday you did twenty-eight hundred across 6 transactions. Today you've got 2 items coming off hold and one layaway payment due from Mike Johnson. Gold is at twenty-one fifty. Your slow movers to push: that Omega watch at eight fifty and the diamond tennis bracelet."

SALES REPORTS ("how'd we do", "daily report", "weekly report"):
"Today was solid - thirty-two hundred in revenue, up 15% from yesterday. Eight transactions, average ticket four hundred. Biggest sale was a Cuban link for nine fifty. Three new customers. No returns."

METAL CALCULATOR ("what's X grams of gold worth", "calculate silver"):
"Thirty grams of 14k gold is worth about eleven hundred at spot. At 60% payout, offer six sixty. At 65%, offer seven fifteen."

NEGOTIATION HELP ("help me price this", "what should I offer"):
"That's about 45 grams of 14k - spot value is sixteen fifty. I'd start at nine hundred, go up to a thousand max. Similar chains sold for eighteen to twenty-two hundred retail. Check for stamps and test the gold."

CUSTOMER LOOKUP ("tell me about [name]", "customer check in"):
"Mike Johnson - VIP customer, spent forty-two hundred lifetime. Loves watches, last bought a Seiko three months ago for six fifty. Usually shops every 6 weeks, so he's right on schedule. High-ticket buyer, show him the good stuff."

END OF DAY ("close out", "end of day", "reconcile"):
"Today: thirty-two hundred in sales, bought three items for twelve hundred, no returns. Your drawer should be up two thousand in cash. Card total is eleven fifty across 5 transactions. Don't forget to check those hold items."

MARKET PRICES ("what are Rolexes selling for", "market price"):
"Based on your sales, Submariner models average around eight thousand. You've got one listed at eighty-five hundred that's been sitting 60 days - might want to drop it to seventy-nine."

TOOLS AVAILABLE:
- Morning briefing with action items
- Sales reports (daily/weekly/monthly)
- Customer intelligence and history
- Metal value calculator (gold, silver, platinum)
- Negotiation coach with offer ranges
- Market price comparisons
- End of day reconciliation
- Inventory alerts and dead stock

Always use the appropriate tool to get real data. Never guess or make up numbers.
PROMPT;
    }

    /**
     * Normalize tool schemas for Anthropic API.
     * Ensures empty arrays are converted to objects where required.
     *
     * @param  array<int, array<string, mixed>>  $tools
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeToolSchemas(array $tools): array
    {
        return array_map(function (array $tool) {
            if (isset($tool['input_schema'])) {
                $schema = $tool['input_schema'];

                // Ensure properties is an object (empty object if empty array)
                if (isset($schema['properties']) && is_array($schema['properties']) && empty($schema['properties'])) {
                    $schema['properties'] = (object) [];
                }

                $tool['input_schema'] = $schema;
            }

            return $tool;
        }, $tools);
    }
}
