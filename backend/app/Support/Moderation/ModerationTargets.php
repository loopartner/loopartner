<?php

namespace App\Support\Moderation;

use InvalidArgumentException;
use LogicException;

/**
 * Registre des types de contenus signalables (config/moderation.php, targets : type =>
 * classe de l'adaptateur). Le noyau lit des noms de classe en configuration : il ne dépend
 * d'aucun module.
 */
final class ModerationTargets
{
    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return array_keys(self::map());
    }

    /**
     * @return list<string>
     */
    public static function publicTypes(): array
    {
        return array_values(array_filter(self::types(), fn (string $type): bool => self::get($type)->publiclyReportable()));
    }

    public static function has(string $type): bool
    {
        return array_key_exists($type, self::map());
    }

    /**
     * @throws InvalidArgumentException type inconnu
     * @throws LogicException adaptateur mal déclaré
     */
    public static function get(string $type): ModerationTarget
    {
        $class = self::map()[$type] ?? throw new InvalidArgumentException("Type de contenu signalable inconnu : {$type}.");
        $target = app($class);

        if (! $target instanceof ModerationTarget) {
            throw new LogicException("L'adaptateur {$class} n'implémente pas ModerationTarget.");
        }

        return $target;
    }

    /**
     * @return array<string, class-string>
     */
    private static function map(): array
    {
        /** @var array<string, class-string> $map */
        $map = (array) config('moderation.targets', []);

        return $map;
    }
}
