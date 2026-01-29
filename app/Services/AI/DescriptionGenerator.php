<?php

namespace App\Services\AI;

use App\Models\AiSuggestion;
use App\Models\Product;

class DescriptionGenerator
{
    protected AIManager $aiManager;

    public function __construct(AIManager $aiManager)
    {
        $this->aiManager = $aiManager;
    }

    public function generate(Product $product, array $options = []): AiSuggestion
    {
        $platform = $options['platform'] ?? null;
        $tone = $options['tone'] ?? 'professional';
        $length = $options['length'] ?? 'medium';

        $systemPrompt = $this->buildSystemPrompt($platform, $tone, $length);
        $userPrompt = $this->buildUserPrompt($product, $platform);

        $response = $this->aiManager->chatWithSystem($systemPrompt, $userPrompt, [
            'feature' => 'description_generation',
            'temperature' => 0.7,
        ]);

        return AiSuggestion::create([
            'store_id' => $product->store_id,
            'suggestable_type' => Product::class,
            'suggestable_id' => $product->id,
            'type' => 'description',
            'platform' => $platform,
            'original_content' => $product->description,
            'suggested_content' => $response->content,
            'metadata' => [
                'tone' => $tone,
                'length' => $length,
                'tokens_used' => $response->totalTokens(),
                'model' => $response->model,
            ],
        ]);
    }

    public function generateBulkDescriptions(array $products, array $options = []): array
    {
        $suggestions = [];
        foreach ($products as $product) {
            $suggestions[] = $this->generate($product, $options);
        }

        return $suggestions;
    }

    public function generateTitle(Product $product, array $options = []): AiSuggestion
    {
        $platform = $options['platform'] ?? null;

        $systemPrompt = $this->buildTitleSystemPrompt($platform);
        $userPrompt = $this->buildTitleUserPrompt($product, $platform);

        $response = $this->aiManager->chatWithSystem($systemPrompt, $userPrompt, [
            'feature' => 'title_generation',
            'temperature' => 0.6,
            'max_tokens' => 256,
        ]);

        return AiSuggestion::create([
            'store_id' => $product->store_id,
            'suggestable_type' => Product::class,
            'suggestable_id' => $product->id,
            'type' => 'title',
            'platform' => $platform,
            'original_content' => $product->title,
            'suggested_content' => trim($response->content),
            'metadata' => [
                'tokens_used' => $response->totalTokens(),
                'model' => $response->model,
            ],
        ]);
    }

    public function generateBulletPoints(Product $product, array $options = []): AiSuggestion
    {
        $platform = $options['platform'] ?? 'amazon';
        $count = $options['count'] ?? 5;

        $systemPrompt = "You are an expert e-commerce copywriter specializing in creating compelling bullet points for product listings. Generate exactly {$count} bullet points that highlight key features and benefits. Each bullet point should start with a capital letter and be concise yet informative.";

        $userPrompt = $this->buildBulletPointsPrompt($product);

        $response = $this->aiManager->chatWithSystem($systemPrompt, $userPrompt, [
            'feature' => 'bullet_points_generation',
            'temperature' => 0.6,
        ]);

        return AiSuggestion::create([
            'store_id' => $product->store_id,
            'suggestable_type' => Product::class,
            'suggestable_id' => $product->id,
            'type' => 'bullet_points',
            'platform' => $platform,
            'original_content' => null,
            'suggested_content' => $response->content,
            'metadata' => [
                'count' => $count,
                'tokens_used' => $response->totalTokens(),
                'model' => $response->model,
            ],
        ]);
    }

    protected function buildSystemPrompt(?string $platform, string $tone, string $length): string
    {
        $lengthGuideline = match ($length) {
            'short' => '50-100 words',
            'medium' => '150-250 words',
            'long' => '300-500 words',
            default => '150-250 words',
        };

        $toneGuideline = match ($tone) {
            'professional' => 'professional and informative',
            'casual' => 'friendly and conversational',
            'luxury' => 'sophisticated and premium',
            'technical' => 'detailed and specification-focused',
            default => 'professional and informative',
        };

        $platformGuideline = $this->getPlatformGuideline($platform);

        return <<<PROMPT
You are an expert e-commerce copywriter specializing in creating compelling product descriptions that drive sales.

Guidelines:
- Write in a {$toneGuideline} tone
- Target length: {$lengthGuideline}
- Focus on benefits, not just features
- Use sensory language where appropriate
- Include relevant keywords naturally for SEO
- Avoid hyperbole and unsubstantiated claims
{$platformGuideline}

Respond with only the product description, no additional commentary.
PROMPT;
    }

    protected function buildUserPrompt(Product $product, ?string $platform): string
    {
        $prompt = "Write a compelling product description for the following product:\n\n";
        $prompt .= "Title: {$product->title}\n";

        if ($product->brand) {
            $prompt .= "Brand: {$product->brand->name}\n";
        }

        if ($product->category) {
            $prompt .= "Category: {$product->category->name}\n";
        }

        if ($product->description) {
            $prompt .= "Current Description: {$product->description}\n";
        }

        if ($product->short_description) {
            $prompt .= "Short Description: {$product->short_description}\n";
        }

        $variant = $product->variants->first();
        if ($variant) {
            $prompt .= "Price: \${$variant->price}\n";
            if ($variant->sku) {
                $prompt .= "SKU: {$variant->sku}\n";
            }
        }

        // Add any product attributes
        if ($product->attributes && $product->attributes->isNotEmpty()) {
            $prompt .= "\nProduct Attributes:\n";
            foreach ($product->attributes as $attr) {
                $prompt .= "- {$attr->name}: {$attr->value}\n";
            }
        }

        return $prompt;
    }

    protected function buildTitleSystemPrompt(?string $platform): string
    {
        $charLimit = match ($platform) {
            'amazon' => 200,
            'ebay' => 80,
            'etsy' => 140,
            'walmart' => 200,
            default => 150,
        };

        return <<<PROMPT
You are an expert at creating SEO-optimized product titles for e-commerce platforms.

Guidelines:
- Maximum {$charLimit} characters
- Include key product attributes (brand, model, size, color, etc.)
- Front-load important keywords
- Avoid all caps and excessive punctuation
- Do not include price or promotional language
- Make it scannable and informative

Respond with only the product title, no additional commentary.
PROMPT;
    }

    protected function buildTitleUserPrompt(Product $product, ?string $platform): string
    {
        $prompt = "Create an optimized product title for:\n\n";
        $prompt .= "Current Title: {$product->title}\n";

        if ($product->brand) {
            $prompt .= "Brand: {$product->brand->name}\n";
        }

        if ($product->category) {
            $prompt .= "Category: {$product->category->name}\n";
        }

        $variant = $product->variants->first();
        if ($variant) {
            if ($variant->option1_value) {
                $prompt .= "Variant Options: {$variant->option1_value}";
                if ($variant->option2_value) {
                    $prompt .= ", {$variant->option2_value}";
                }
                if ($variant->option3_value) {
                    $prompt .= ", {$variant->option3_value}";
                }
                $prompt .= "\n";
            }
        }

        if ($platform) {
            $prompt .= "\nTarget Platform: {$platform}";
        }

        return $prompt;
    }

    protected function buildBulletPointsPrompt(Product $product): string
    {
        $prompt = "Create compelling bullet points for:\n\n";
        $prompt .= "Product: {$product->title}\n";

        if ($product->description) {
            $prompt .= "Description: {$product->description}\n";
        }

        if ($product->brand) {
            $prompt .= "Brand: {$product->brand->name}\n";
        }

        $variant = $product->variants->first();
        if ($variant) {
            $prompt .= "Price: \${$variant->price}\n";
        }

        return $prompt;
    }

    protected function getPlatformGuideline(?string $platform): string
    {
        return match ($platform) {
            'amazon' => "- Format for Amazon: Use HTML formatting where appropriate (<b>, <br>, <ul>/<li>)\n- Include relevant search terms\n- Focus on A9 algorithm optimization",
            'ebay' => "- Format for eBay: Use clean HTML formatting\n- Include item specifics and condition details\n- Emphasize trust signals and quality",
            'etsy' => "- Format for Etsy: Emphasize handmade/unique aspects\n- Use storytelling to connect with buyers\n- Include materials and process details",
            'shopify' => "- Format for Shopify: Use clean, semantic formatting\n- Focus on brand story and value proposition",
            'walmart' => "- Format for Walmart: Focus on value and quality\n- Include product specifications\n- Keep language family-friendly",
            default => '',
        };
    }
}
