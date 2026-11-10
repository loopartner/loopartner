<?php

namespace App\Support\Media\Exceptions;

use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * L'utilisateur a trop de médias envoyés mais jamais rattachés (rendue en 429).
 */
class TooManyPendingMedia extends TooManyRequestsHttpException
{
    public function __construct(int $max)
    {
        parent::__construct(
            null,
            "Vous avez déjà {$max} images envoyées mais pas encore utilisées. Utilisez-les ou attendez leur suppression automatique (24 heures) avant d'en envoyer d'autres.",
        );
    }
}
