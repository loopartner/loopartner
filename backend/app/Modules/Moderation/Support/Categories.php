<?php

namespace App\Modules\Moderation\Support;

use App\Modules\Moderation\Enums\NoticePriority;
use App\Support\Locale;

/**
 * Catégories de signalement (config/moderation.php, categories).
 */
final class Categories
{
    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys((array) config('moderation.categories'));
    }

    public static function exists(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    public static function priority(string $key): NoticePriority
    {
        return NoticePriority::from((string) config("moderation.categories.{$key}.priority", 'standard'));
    }

    /**
     * Nom et email du signalant facultatifs (DSA art. 16 §2 c).
     */
    public static function identityOptional(string $key): bool
    {
        return (bool) config("moderation.categories.{$key}.identity_optional", false);
    }

    /**
     * Catégorie pour laquelle aucune image du contenu n'est réaffichée dans l'administration
     * (abus sexuels sur mineurs) : la copie conservée reste textuelle.
     */
    public static function withholdsImages(string $key): bool
    {
        return $key === 'child_sexual_abuse';
    }

    public static function label(string $key, ?string $locale = null): string
    {
        $locale ??= Locale::DEFAULT;
        $labels = (array) config("moderation.categories.{$key}.label", []);

        return (string) ($labels[$locale] ?? $labels[Locale::DEFAULT] ?? $key);
    }

    /**
     * @return list<array{key: string, label: string, priority: string, identity_optional: bool}>
     */
    public static function describe(string $locale): array
    {
        return array_map(fn (string $key): array => [
            'key' => $key,
            'label' => self::label($key, $locale),
            'priority' => self::priority($key)->value,
            'identity_optional' => self::identityOptional($key),
        ], self::keys());
    }
}
