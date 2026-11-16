<?php

namespace App\Support\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;

/**
 * Catégories de notifications (config/notifications.php) et préférences d'un utilisateur.
 */
final class NotificationCategories
{
    public static function exists(string $category): bool
    {
        return self::definition($category) !== null;
    }

    public static function isMandatory(string $category): bool
    {
        return (bool) (self::definition($category)['mandatory'] ?? false);
    }

    /**
     * @return list<string>
     */
    public static function channels(): array
    {
        return array_values(array_map('strval', (array) config('notifications.channels')));
    }

    /**
     * Catégories que l'utilisateur voit et règle, selon ses rôles.
     *
     * @return list<string>
     */
    public static function visibleTo(User $user): array
    {
        $roles = $user->getRoleNames()->all();
        $visible = [];

        foreach ((array) config('notifications.categories') as $category => $definition) {
            if (array_intersect($roles, (array) ($definition['roles'] ?? [])) !== []) {
                $visible[] = (string) $category;
            }
        }

        return $visible;
    }

    public static function label(string $category, string $locale): string
    {
        $labels = (array) (self::definition($category)['label'] ?? []);

        return (string) ($labels[$locale] ?? $labels['fr'] ?? $category);
    }

    /**
     * Définition d'une catégorie. Les noms contiennent un point : pas de notation pointée.
     *
     * @return array<string, mixed>|null
     */
    private static function definition(string $category): ?array
    {
        $definition = ((array) config('notifications.categories'))[$category] ?? null;

        return is_array($definition) ? $definition : null;
    }

    /**
     * L'utilisateur a-t-il désactivé cette catégorie sur ce canal ? Jamais pour une catégorie
     * obligatoire ou inconnue.
     */
    public static function isDisabled(User $user, string $category, string $channel): bool
    {
        if (! self::exists($category) || self::isMandatory($category)) {
            return false;
        }

        return NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('category', $category)
            ->where('channel', $channel)
            ->where('enabled', false)
            ->exists();
    }

    /**
     * Préférences de l'utilisateur pour les catégories qu'il voit (activé par défaut).
     *
     * @return list<array{category: string, label: string, mandatory: bool, channels: array<string, bool>}>
     */
    public static function forUser(User $user, string $locale): array
    {
        $disabled = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('enabled', false)
            ->get(['category', 'channel'])
            ->map(fn (NotificationPreference $preference): string => $preference->category.'|'.$preference->channel)
            ->all();

        return array_map(function (string $category) use ($disabled, $locale): array {
            $mandatory = self::isMandatory($category);
            $channels = [];

            foreach (self::channels() as $channel) {
                $channels[$channel] = $mandatory || ! in_array($category.'|'.$channel, $disabled, true);
            }

            return [
                'category' => $category,
                'label' => self::label($category, $locale),
                'mandatory' => $mandatory,
                'channels' => $channels,
            ];
        }, self::visibleTo($user));
    }
}
