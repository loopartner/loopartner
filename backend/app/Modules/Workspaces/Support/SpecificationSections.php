<?php

namespace App\Modules\Workspaces\Support;

/**
 * Sections d'un cahier des charges, à liste fermée (saisie et copilote) : contexte, objectifs,
 * périmètre, livrables, contraintes, budget indicatif, calendrier, critères de choix du
 * prestataire. Une section absente vaut null.
 */
final class SpecificationSections
{
    public const KEYS = ['context', 'objectives', 'scope', 'deliverables', 'constraints', 'budget', 'schedule', 'selection_criteria'];

    /**
     * Sections complètes (toutes les clés, dans l'ordre), à partir de données validées.
     *
     * @param  array<array-key, mixed>  $sections
     * @return array<string, string|null>
     */
    public static function normalize(array $sections): array
    {
        $result = [];

        foreach (self::KEYS as $key) {
            $value = $sections[$key] ?? null;
            $result[$key] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        return $result;
    }
}
