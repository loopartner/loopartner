<?php

namespace App\Rules;

use App\Models\ServiceCategory;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Slug d'une catégorie de services active (une sous-catégorie dont le parent est désactivé
 * est refusée).
 */
class ActiveServiceCategory implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! ServiceCategory::query()->active()->where('slug', $value)->exists()) {
            $fail('Le champ :attribute doit être une catégorie de services existante et active.');
        }
    }
}
