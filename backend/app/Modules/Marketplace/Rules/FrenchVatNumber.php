<?php

namespace App\Modules\Marketplace\Rules;

use App\Modules\Marketplace\Support\BusinessNumbers;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Numéro de TVA intracommunautaire français (FR + clé de 2 chiffres + SIREN) dont la clé
 * correspond au SIREN déclaré.
 */
class FrenchVatNumber implements ValidationRule
{
    public function __construct(private ?string $siren) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $number = BusinessNumbers::compact(is_string($value) ? $value : null);
        $siren = BusinessNumbers::compact($this->siren);

        if ($number === null || $siren === null || ! BusinessNumbers::isFrenchVatNumber($number, $siren)) {
            $fail('Le numéro de TVA intracommunautaire ne correspond pas au SIREN.');
        }
    }
}
