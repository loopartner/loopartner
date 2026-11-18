<?php

namespace App\Support\Ai\Exceptions;

use RuntimeException;

/**
 * Échec du fournisseur : connection, timeout, http_<statut>, configuration. Jamais le corps de
 * la réponse ni la clé. Traduit en 503 (AiUnavailable "provider") par AiGateway.
 */
class AiProviderFailure extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
