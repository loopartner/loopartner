<?php

namespace App\Modules\Moderation\Support;

use App\Support\Locale;

/**
 * Nom de chaque type de contenu signalable, pour les emails et les réponses. Un type déclaré
 * dans config/moderation.php (targets) sans nom ici fait échouer un test.
 */
final class TargetTypes
{
    /**
     * @var array<string, array{fr: string, en: string}>
     */
    public const LABELS = [
        'provider_profile' => ['fr' => 'profil de prestataire', 'en' => 'provider profile'],
        'project' => ['fr' => 'projet', 'en' => 'project'],
        'message' => ['fr' => 'message', 'en' => 'message'],
        'match_request' => ['fr' => 'demande de mise en relation', 'en' => 'connection request'],
        'event' => ['fr' => 'événement', 'en' => 'event'],
        'event_announcement' => ['fr' => 'annonce d\'événement', 'en' => 'event announcement'],
        'article' => ['fr' => 'article', 'en' => 'article'],
    ];

    public static function label(string $type, ?string $locale = null): string
    {
        $locale ??= Locale::DEFAULT;

        return self::LABELS[$type][$locale] ?? self::LABELS[$type][Locale::DEFAULT] ?? $type;
    }
}
