<?php

namespace App\Support\Moderation;

use Illuminate\Database\Eloquent\Model;

/**
 * Restriction de visibilité posée par la modération sur un contenu (colonnes
 * moderation_restricted_at et moderation_decision_uuid, voir ModerationTarget).
 *
 * Le contenu n'est pas « modifié » au sens de son auteur : updated_at ne change pas (les
 * purges fondées sur l'absence de modification restent justes).
 */
final class Restriction
{
    public static function apply(Model $content, string $decisionUuid): void
    {
        if ($content->getAttribute('moderation_restricted_at') !== null) {
            return;
        }

        self::write($content, now(), $decisionUuid);
    }

    /**
     * Lève la restriction, seulement si elle vient de cette décision.
     */
    public static function lift(Model $content, string $decisionUuid): void
    {
        if ($content->getAttribute('moderation_decision_uuid') !== $decisionUuid) {
            return;
        }

        self::write($content, null, null);
    }

    public static function decisionOf(Model $content): ?string
    {
        $uuid = $content->getAttribute('moderation_decision_uuid');

        return $content->getAttribute('moderation_restricted_at') !== null && is_string($uuid) ? $uuid : null;
    }

    /**
     * Champ "moderation" des réponses destinées au propriétaire du contenu : masqué ou non,
     * et la décision (exposé des motifs : GET /me/moderation/decisions/{uuid}).
     *
     * @return array{restricted: bool, decision: string|null}
     */
    public static function view(Model $content): array
    {
        $decision = self::decisionOf($content);

        return ['restricted' => $decision !== null, 'decision' => $decision];
    }

    private static function write(Model $content, mixed $at, ?string $decisionUuid): void
    {
        $timestamps = $content->timestamps;
        $content->timestamps = false;

        try {
            $content->forceFill([
                'moderation_restricted_at' => $at,
                'moderation_decision_uuid' => $decisionUuid,
            ])->save();
        } finally {
            $content->timestamps = $timestamps;
        }
    }
}
