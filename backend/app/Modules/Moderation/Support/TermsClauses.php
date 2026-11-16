<?php

namespace App\Modules\Moderation\Support;

use App\Support\Locale;

/**
 * Clauses des CGU citées par les décisions fondées sur les conditions générales (DSA art. 17
 * §3 e ; config/moderation.php, terms_clauses). "reference" est l'article des CGU, renseigné
 * une fois les CGU rédigées par le juriste (security:check-config l'exige en production).
 */
final class TermsClauses
{
    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys((array) config('moderation.terms_clauses'));
    }

    /**
     * @return array{key: string, reference: string|null, label: string}|null
     */
    public static function describe(?string $key, string $locale): ?array
    {
        if ($key === null || ! in_array($key, self::keys(), true)) {
            return null;
        }

        $labels = (array) config("moderation.terms_clauses.{$key}.label", []);
        $reference = config("moderation.terms_clauses.{$key}.reference");

        return [
            'key' => $key,
            'reference' => is_string($reference) && $reference !== '' ? $reference : null,
            'label' => (string) ($labels[$locale] ?? $labels[Locale::DEFAULT] ?? $key),
        ];
    }

    /**
     * @return list<array{key: string, reference: string|null, label: string}>
     */
    public static function all(string $locale): array
    {
        return array_values(array_filter(array_map(fn (string $key): ?array => self::describe($key, $locale), self::keys())));
    }
}
