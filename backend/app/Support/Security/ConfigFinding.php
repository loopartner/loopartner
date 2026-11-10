<?php

namespace App\Support\Security;

/**
 * Problème de configuration relevé par ConfigAudit. Ne contient jamais la valeur d'un secret.
 */
final readonly class ConfigFinding
{
    public const ERROR = 'erreur';

    public const WARNING = 'avertissement';

    public function __construct(
        public string $level,
        public string $variable,
        public string $message,
    ) {}

    public function isError(): bool
    {
        return $this->level === self::ERROR;
    }
}
