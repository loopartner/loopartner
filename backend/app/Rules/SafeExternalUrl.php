<?php

namespace App\Rules;

use App\Support\Links\ExternalLink;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Lien externe saisi par un utilisateur : https uniquement, nom de domaine public, ni adresse
 * IP, ni hôte local, ni raccourcisseur, ni domaine refusé (voir App\Support\Links\ExternalLink).
 *
 * À combiner avec "string" et une longueur maximale ("max:2048").
 */
class SafeExternalUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $problem = ExternalLink::problem($value);

        if ($problem !== null) {
            $fail($problem);
        }
    }
}
