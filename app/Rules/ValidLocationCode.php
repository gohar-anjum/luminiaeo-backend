<?php

namespace App\Rules;

use App\Services\LocationCodeService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidLocationCode implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) {
            return; // Let 'required' or 'nullable' rules handle empty values
        }

        $locationCodeService = app(LocationCodeService::class);
        
        if (!$locationCodeService->isValid((int) $value)) {
            $fail("The {$attribute} must be a valid location code.");
        }
    }
}
