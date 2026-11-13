<?php

namespace App\Modules\Compliance\Support;

use App\Modules\Compliance\Models\Deadline;

/**
 * Titre d'une échéance dans une langue : titre traduit de la règle, ou titre saisi par
 * l'utilisateur pour une échéance personnelle.
 */
final class DeadlineTitle
{
    public static function of(Deadline $deadline, string $locale): string
    {
        if ($deadline->isFromRule()) {
            return $deadline->rule?->titleIn($locale) ?? (string) $deadline->rule_code;
        }

        return (string) $deadline->title;
    }
}
