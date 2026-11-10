<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuse tout texte contenant une balise HTML, un commentaire HTML ou une instruction "<?".
 *
 * Un "<" isolé reste accepté ("a < b", "prix <10 €") : seul un "<" suivi d'une lettre,
 * de "/", "!" ou "?" est considéré comme du HTML.
 */
class NoHtml implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && preg_match('/<[a-zA-Z\/!?]/', $value) === 1) {
            $fail('Le champ :attribute ne doit pas contenir de HTML.');
        }
    }
}
