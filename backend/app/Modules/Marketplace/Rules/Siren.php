<?php

namespace App\Modules\Marketplace\Rules;

use App\Modules\Marketplace\Support\BusinessNumbers;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * SIREN (9 chiffres) dont la clé de Luhn est juste. Contrôle de forme seulement (décision 37) :
 * l'existence de l'entreprise sera vérifiée au lot 6 par l'API publique de l'État.
 */
class Siren implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $number = BusinessNumbers::compact(is_string($value) ? $value : null);

        if ($number === null || ! BusinessNumbers::isSiren($number)) {
            $fail('Le SIREN doit compter 9 chiffres et être valide.');
        }
    }
}
