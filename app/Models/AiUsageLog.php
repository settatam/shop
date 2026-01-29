<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsageLog extends Model
{
    use BelongsToStore, HasFactory;

    protected $fillable = [
        'store_id',
        'user_id',
        'provider',
        'model',
        'feature',
        'input_tokens',
        'output_tokens',
        'cost',
        'duration_ms',
        'successful',
        'error_message',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost' => 'decimal:6',
            'duration_ms' => 'integer',
            'successful' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function totalTokens(): int
    {
        return $this->input_tokens + $this->output_tokens;
    }

    public static function logUsage(
        int $storeId,
        string $provider,
        string $model,
        string $feature,
        int $inputTokens,
        int $outputTokens,
        ?int $durationMs = null,
        ?int $userId = null
    ): self {
        $cost = self::calculateCost($provider, $model, $inputTokens, $outputTokens);

        return self::create([
            'store_id' => $storeId,
            'user_id' => $userId,
            'provider' => $provider,
            'model' => $model,
            'feature' => $feature,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost' => $cost,
            'duration_ms' => $durationMs,
            'successful' => true,
        ]);
    }

    public static function logError(
        int $storeId,
        string $provider,
        string $model,
        string $feature,
        string $errorMessage,
        ?int $userId = null
    ): self {
        return self::create([
            'store_id' => $storeId,
            'user_id' => $userId,
            'provider' => $provider,
            'model' => $model,
            'feature' => $feature,
            'successful' => false,
            'error_message' => $errorMessage,
        ]);
    }

    protected static function calculateCost(string $provider, string $model, int $inputTokens, int $outputTokens): float
    {
        // Pricing per 1M tokens (approximate as of 2024)
        $pricing = [
            'openai' => [
                'gpt-4o' => ['input' => 5.00, 'output' => 15.00],
                'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
                'gpt-4-turbo' => ['input' => 10.00, 'output' => 30.00],
                'gpt-3.5-turbo' => ['input' => 0.50, 'output' => 1.50],
            ],
            'anthropic' => [
                'claude-3-5-sonnet-20241022' => ['input' => 3.00, 'output' => 15.00],
                'claude-3-5-haiku-20241022' => ['input' => 0.80, 'output' => 4.00],
                'claude-3-opus-20240229' => ['input' => 15.00, 'output' => 75.00],
            ],
        ];

        $rates = $pricing[$provider][$model] ?? ['input' => 0, 'output' => 0];

        $inputCost = ($inputTokens / 1_000_000) * $rates['input'];
        $outputCost = ($outputTokens / 1_000_000) * $rates['output'];

        return $inputCost + $outputCost;
    }

    public function scopeForFeature($query, string $feature)
    {
        return $query->where('feature', $feature);
    }

    public function scopeSuccessful($query)
    {
        return $query->where('successful', true);
    }

    public function scopeFailed($query)
    {
        return $query->where('successful', false);
    }

    public function scopeForPeriod($query, $start, $end)
    {
        return $query->whereBetween('created_at', [$start, $end]);
    }
}
