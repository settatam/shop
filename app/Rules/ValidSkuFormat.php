<?php

namespace App\Rules;

use App\Services\Sku\SkuGeneratorService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidSkuFormat implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || empty($value)) {
            return;
        }

        $service = new SkuGeneratorService;
        $result = $service->validateFormat($value);

        if (! $result['valid']) {
            $fail(implode(' ', $result['errors']));
        }
    }
}
