<?php

namespace App\Rules;

use App\Support\Links\ExternalLink;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Texte libre dont les liens et noms de domaine éventuels respectent les mêmes règles que les
 * liens externes (SafeExternalUrl) : une adresse en http://, un raccourcisseur ou un domaine
 * refusé écrit dans une description est refusé comme dans un champ de lien.
 *
 * Le frontend ne doit jamais transformer ces liens en liens cliquables.
 */
class SafeLinksInText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        foreach (ExternalLink::extract($value) as $link) {
            $problem = ExternalLink::textLinkProblem($link);

            if ($problem !== null) {
                $fail('Le champ :attribute contient un lien refusé ('.mb_strimwidth($link, 0, 60, '…').') : '.lcfirst($problem));

                return;
            }
        }
    }
}
