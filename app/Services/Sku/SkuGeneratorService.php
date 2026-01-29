<?php

namespace App\Services\Sku;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SkuSequence;
use App\Models\Store;
use Illuminate\Support\Str;

class SkuGeneratorService
{
    /**
     * Supported format variables with their patterns.
     *
     * @var array<string, string>
     */
    protected const VARIABLE_PATTERNS = [
        'category_code' => '/\{category_code\}/',
        'category_name' => '/\{category_name(?::(\d+))?\}/',
        'product_id' => '/\{product_id(?::(\d+))?\}/',
        'variant_id' => '/\{variant_id(?::(\d+))?\}/',
        'sequence' => '/\{sequence(?::(\d+))?\}/',
        'year' => '/\{year(?::(\d+))?\}/',
        'month' => '/\{month\}/',
        'day' => '/\{day\}/',
        'random' => '/\{random:(\d+)\}/',
    ];

    /**
     * Generate a SKU for a product variant based on the category's SKU format.
     */
    public function generate(
        Category $category,
        Product $product,
        ?ProductVariant $variant = null,
        ?Store $store = null
    ): string {
        $format = $category->getEffectiveSkuFormat();

        if (! $format) {
            throw new \InvalidArgumentException('Category has no SKU format configured');
        }

        $store = $store ?? $product->store;

        return $this->parseFormat($format, $category, $product, $variant, $store);
    }

    /**
     * Generate a preview SKU for UI display.
     * Uses placeholder values instead of real data.
     */
    public function preview(Category $category, ?string $format = null): string
    {
        $format = $format ?? $category->getEffectiveSkuFormat();

        if (! $format) {
            return '';
        }

        return $this->parseFormatPreview($format, $category);
    }

    /**
     * Validate a SKU format string.
     *
     * @return array{valid: bool, errors: array<string>}
     */
    public function validateFormat(string $format): array
    {
        $errors = [];

        // Check for balanced braces
        $openCount = substr_count($format, '{');
        $closeCount = substr_count($format, '}');

        if ($openCount !== $closeCount) {
            $errors[] = 'Unbalanced braces in format';
        }

        // Extract all variables
        preg_match_all('/\{([^}]+)\}/', $format, $matches);

        foreach ($matches[1] as $variable) {
            if (! $this->isValidVariable($variable)) {
                $errors[] = "Invalid variable: {{$variable}}";
            }
        }

        // Check that format produces non-empty result
        if (preg_replace('/\{[^}]+\}/', '', $format) === '' && empty($matches[0])) {
            $errors[] = 'Format must contain at least one variable or static text';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Get the next sequence value for a category without incrementing.
     */
    public function peekNextSequence(Category $category, Store $store): int
    {
        $sequence = SkuSequence::getOrCreate($category, $store);

        return $sequence->current_value + 1;
    }

    /**
     * Get available format variables with descriptions.
     *
     * @return array<string, string>
     */
    public static function getAvailableVariables(): array
    {
        return [
            '{category_code}' => 'Category SKU prefix (e.g., JEW)',
            '{category_name:N}' => 'First N characters of category name',
            '{product_id}' => 'Product ID number',
            '{product_id:N}' => 'Product ID zero-padded to N digits',
            '{variant_id}' => 'Variant ID number',
            '{variant_id:N}' => 'Variant ID zero-padded to N digits',
            '{sequence}' => 'Auto-incrementing sequence number',
            '{sequence:N}' => 'Sequence zero-padded to N digits',
            '{year}' => 'Current 4-digit year (2026)',
            '{year:2}' => 'Current 2-digit year (26)',
            '{month}' => 'Current 2-digit month (01-12)',
            '{day}' => 'Current 2-digit day (01-31)',
            '{random:N}' => 'Random alphanumeric string of N characters',
        ];
    }

    /**
     * Parse the format string and replace variables with actual values.
     */
    protected function parseFormat(
        string $format,
        Category $category,
        Product $product,
        ?ProductVariant $variant,
        Store $store
    ): string {
        $result = $format;

        // Category code
        $result = preg_replace(
            self::VARIABLE_PATTERNS['category_code'],
            $category->getEffectiveSkuPrefix() ?? '',
            $result
        );

        // Category name with optional truncation
        $result = preg_replace_callback(
            self::VARIABLE_PATTERNS['category_name'],
            fn ($matches) => $this->truncate(
                strtoupper($category->name),
                isset($matches[1]) ? (int) $matches[1] : null
            ),
            $result
        );

        // Product ID with optional padding
        $result = preg_replace_callback(
            self::VARIABLE_PATTERNS['product_id'],
            fn ($matches) => $this->pad(
                (string) $product->id,
                isset($matches[1]) ? (int) $matches[1] : 0
            ),
            $result
        );

        // Variant ID with optional padding
        $result = preg_replace_callback(
            self::VARIABLE_PATTERNS['variant_id'],
            fn ($matches) => $this->pad(
                (string) ($variant?->id ?? 0),
                isset($matches[1]) ? (int) $matches[1] : 0
            ),
            $result
        );

        // Sequence with optional padding
        $result = preg_replace_callback(
            self::VARIABLE_PATTERNS['sequence'],
            function ($matches) use ($category, $store) {
                $sequence = SkuSequence::getOrCreate($category, $store);
                $value = $sequence->incrementAndGet();

                return $this->pad(
                    (string) $value,
                    isset($matches[1]) ? (int) $matches[1] : 0
                );
            },
            $result
        );

        // Year with optional truncation
        $result = preg_replace_callback(
            self::VARIABLE_PATTERNS['year'],
            fn ($matches) => isset($matches[1]) && $matches[1] === '2'
                ? substr(date('Y'), -2)
                : date('Y'),
            $result
        );

        // Month
        $result = preg_replace(
            self::VARIABLE_PATTERNS['month'],
            date('m'),
            $result
        );

        // Day
        $result = preg_replace(
            self::VARIABLE_PATTERNS['day'],
            date('d'),
            $result
        );

        // Random string
        $result = preg_replace_callback(
            self::VARIABLE_PATTERNS['random'],
            fn ($matches) => strtoupper(Str::random((int) $matches[1])),
            $result
        );

        return $result;
    }

    /**
     * Parse the format string with preview/placeholder values.
     */
    protected function parseFormatPreview(string $format, Category $category): string
    {
        $result = $format;

        // Category code
        $result = preg_replace(
            self::VARIABLE_PATTERNS['category_code'],
            $category->getEffectiveSkuPrefix() ?? 'CAT',
            $result
        );

        // Category name with optional truncation
        $result = preg_replace_callback(
            self::VARIABLE_PATTERNS['category_name'],
            fn ($matches) => $this->truncate(
                strtoupper($category->name),
                isset($matches[1]) ? (int) $matches[1] : null
            ),
            $result
        );

        // Product ID with optional padding
        $result = preg_replace_callback(
            self::VARIABLE_PATTERNS['product_id'],
            fn ($matches) => $this->pad(
                '123',
                isset($matches[1]) ? (int) $matches[1] : 0
            ),
            $result
        );

        // Variant ID with optional padding
        $result = preg_replace_callback(
            self::VARIABLE_PATTERNS['variant_id'],
            fn ($matches) => $this->pad(
                '456',
                isset($matches[1]) ? (int) $matches[1] : 0
            ),
            $result
        );

        // Sequence with optional padding - show next value
        $result = preg_replace_callback(
            self::VARIABLE_PATTERNS['sequence'],
            function ($matches) use ($category) {
                // For preview, show what the next value would be
                $sequence = $category->skuSequence;
                $nextValue = ($sequence?->current_value ?? 0) + 1;

                return $this->pad(
                    (string) $nextValue,
                    isset($matches[1]) ? (int) $matches[1] : 0
                );
            },
            $result
        );

        // Year with optional truncation
        $result = preg_replace_callback(
            self::VARIABLE_PATTERNS['year'],
            fn ($matches) => isset($matches[1]) && $matches[1] === '2'
                ? substr(date('Y'), -2)
                : date('Y'),
            $result
        );

        // Month
        $result = preg_replace(
            self::VARIABLE_PATTERNS['month'],
            date('m'),
            $result
        );

        // Day
        $result = preg_replace(
            self::VARIABLE_PATTERNS['day'],
            date('d'),
            $result
        );

        // Random string - show example
        $result = preg_replace_callback(
            self::VARIABLE_PATTERNS['random'],
            fn ($matches) => str_repeat('X', (int) $matches[1]),
            $result
        );

        return $result;
    }

    /**
     * Check if a variable specification is valid.
     */
    protected function isValidVariable(string $variable): bool
    {
        $validPatterns = [
            '/^category_code$/',
            '/^category_name(:\d+)?$/',
            '/^product_id(:\d+)?$/',
            '/^variant_id(:\d+)?$/',
            '/^sequence(:\d+)?$/',
            '/^year(:\d+)?$/',
            '/^month$/',
            '/^day$/',
            '/^random:\d+$/',
        ];

        foreach ($validPatterns as $pattern) {
            if (preg_match($pattern, $variable)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Zero-pad a string to the specified length.
     */
    protected function pad(string $value, int $length): string
    {
        if ($length <= 0) {
            return $value;
        }

        return str_pad($value, $length, '0', STR_PAD_LEFT);
    }

    /**
     * Truncate a string to the specified length.
     */
    protected function truncate(string $value, ?int $length): string
    {
        if ($length === null) {
            return $value;
        }

        return substr($value, 0, $length);
    }
}
