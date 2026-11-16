<?php

namespace App\Modules\Moderation\Support;

use App\Models\User;

/**
 * Empreintes qui identifient un signalant ou l'auteur d'un recours sans garder de donnée
 * lisible : un compte ("user:<id>") ou un email (empreinte HMAC, comme le journal security).
 */
final class Keys
{
    public static function reporter(?User $user, ?string $emailHash): ?string
    {
        return match (true) {
            $user !== null => 'user:'.$user->id,
            $emailHash !== null => 'email:'.$emailHash,
            default => null,
        };
    }

    /**
     * Un même signalant ne signale qu'une fois un contenu en cours d'examen (index unique).
     * Même formule que la migration qui a repris les signalements de la messagerie.
     */
    public static function active(string $type, string $ref, string $reporterKey): string
    {
        return hash('sha256', $type.'|'.$ref.'|'.$reporterKey);
    }

    public static function appellant(string $key): string
    {
        return hash('sha256', 'appeal|'.$key);
    }
}
