<?php

namespace App\Modules\Directory\Support;

/**
 * Convertit les données validées d'un projet en attributs du modèle.
 *
 * Seuls les champs présents sont renvoyés (PATCH partiel). La catégorie (slug) est traitée à
 * part par les Actions, car la colonne contient l'id interne.
 */
final class ProjectAttributes
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fromInput(array $data): array
    {
        $attributes = [];

        foreach (['title', 'description', 'currency', 'country', 'city', 'desired_start_date'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        foreach (['budget_min', 'budget_max'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = is_numeric($data[$field]) ? (int) $data[$field] : null;
            }
        }

        if (array_key_exists('remote_ok', $data)) {
            $attributes['remote_ok'] = filter_var($data['remote_ok'], FILTER_VALIDATE_BOOLEAN);
        }

        return $attributes;
    }
}
