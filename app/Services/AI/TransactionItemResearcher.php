<?php

namespace App\Services\AI;

use App\Models\AiSuggestion;
use App\Models\AiUsageLog;
use App\Models\StoreIntegration;
use App\Models\TransactionItem;
use Illuminate\Support\Facades\Http;

class TransactionItemResearcher
{
    protected string $apiKey;

    protected string $model;

    protected string $baseUrl = 'https://api.anthropic.com/v1';

    public function __construct()
    {
        // Default from config, can be overridden by store integration
        $this->apiKey = config('services.anthropic.api_key', '');
        $this->model = config('services.anthropic.model', 'claude-sonnet-4-20250514');
    }

    /**
     * Generate AI research for a transaction item.
     *
     * @return array<string, mixed>
     */
    public function generateResearch(TransactionItem $item): array
    {
        $item->load(['category', 'images', 'transaction']);

        // Check for store-specific Anthropic integration
        $storeId = $item->transaction->store_id;
        $integration = StoreIntegration::findActiveForStore($storeId, StoreIntegration::PROVIDER_ANTHROPIC);

        if ($integration) {
            $this->apiKey = $integration->getAnthropicApiKey();
            $this->model = $integration->getAnthropicModel();
        }

        if (empty($this->apiKey)) {
            return [
                'error' => 'Anthropic API key not configured. Please add your API key in Settings → Integrations.',
            ];
        }

        $prompt = $this->buildPrompt($item);
        $messages = [['role' => 'user', 'content' => $prompt]];

        // Include images if available
        if ($item->images->isNotEmpty()) {
            $content = [];
            foreach ($item->images->take(4) as $image) {
                if ($image->url) {
                    $content[] = [
                        'type' => 'image',
                        'source' => [
                            'type' => 'url',
                            'url' => $image->url,
                        ],
                    ];
                }
            }
            $content[] = [
                'type' => 'text',
                'text' => $prompt,
            ];
            $messages = [['role' => 'user', 'content' => $content]];
        }

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ])->timeout(120)->post("{$this->baseUrl}/messages", [
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => 2048,
            'system' => 'You are an expert appraiser and market researcher for jewelry, precious metals, and luxury goods. Provide detailed, accurate assessments. Always respond with valid JSON.',
        ]);

        if ($response->failed()) {
            return [
                'error' => 'Failed to generate research. Please try again.',
            ];
        }

        $responseData = $response->json();
        $text = $responseData['data']['content'][0]['text'] ?? $responseData['content'][0]['text'] ?? '';

        // Parse the JSON response
        $research = $this->parseResearch($text);

        // Store in the item
        $item->update([
            'ai_research' => $research,
            'ai_research_generated_at' => now(),
        ]);

        // Log usage
        $store = $item->transaction->store;
        $inputTokens = $responseData['usage']['input_tokens'] ?? 0;
        $outputTokens = $responseData['usage']['output_tokens'] ?? 0;

        AiUsageLog::logUsage(
            storeId: $store->id,
            provider: 'anthropic',
            model: $this->model,
            feature: 'transaction_item_research',
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            userId: auth()->id(),
        );

        // Create suggestion record
        AiSuggestion::create([
            'store_id' => $store->id,
            'suggestable_type' => TransactionItem::class,
            'suggestable_id' => $item->id,
            'type' => 'item_research',
            'suggested_content' => json_encode($research),
            'status' => 'pending',
        ]);

        return $research;
    }

    protected function buildPrompt(TransactionItem $item): string
    {
        $details = "Analyze this item and provide a market research report:\n\n";
        $details .= "Title: {$item->title}\n";

        if ($item->description) {
            $details .= "Description: {$item->description}\n";
        }
        if ($item->category) {
            $details .= "Category: {$item->category->name}\n";
        }
        if ($item->precious_metal) {
            $details .= 'Metal Type: '.str_replace('_', ' ', $item->precious_metal)."\n";
        }
        if ($item->dwt) {
            $details .= "Weight (DWT): {$item->dwt}\n";
        }
        if ($item->condition) {
            $details .= 'Condition: '.str_replace('_', ' ', $item->condition)."\n";
        }
        if ($item->price) {
            $details .= "Estimated Value: \${$item->price}\n";
        }
        if ($item->buy_price) {
            $details .= "Buy Price: \${$item->buy_price}\n";
        }

        $details .= "\nRespond with a JSON object containing:\n";
        $details .= "{\n";
        $details .= '  "market_value": { "min": number, "max": number, "avg": number, "confidence": "low"|"medium"|"high", "reasoning": "string" },'."\n";
        $details .= '  "pricing_recommendation": { "suggested_retail": number, "suggested_wholesale": number, "notes": "string" },'."\n";
        $details .= '  "item_analysis": { "description": "string", "notable_features": ["string"], "condition_notes": "string" }'."\n";
        $details .= "}\n";

        return $details;
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseResearch(string $text): array
    {
        // Try to extract JSON from the response
        $jsonMatch = preg_match('/\{[\s\S]*\}/', $text, $matches);

        if ($jsonMatch) {
            $parsed = json_decode($matches[0], true);
            if ($parsed) {
                return $parsed;
            }
        }

        // Fallback if JSON parsing fails
        return [
            'raw_analysis' => $text,
            'parse_error' => true,
        ];
    }
}
