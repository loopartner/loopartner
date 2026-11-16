<?php

namespace App\Modules\Directory\Actions;

use App\Modules\Directory\Models\UserBlock;
use App\Modules\Moderation\Actions\CountNoticesAgainst;

/**
 * Action publique du module Directory : signaux de modération sur un utilisateur, pour
 * l'historique présenté aux modérateurs par d'autres modules (Events, Blog), sans exposer de
 * modèle.
 *
 * Seulement des nombres : signalements reçus par ses contenus (module Moderation, tous contenus
 * confondus) et encore en cours d'examen, blocages subis. Ni contenu signalé, ni commentaire, ni
 * identité de qui a signalé ou bloqué. Lecture seule.
 *
 * @phpstan-type ModerationSignals array{reports_received: int, reports_open: int, blocked_by_users: int}
 */
class GetUserModerationSignals
{
    public function __construct(private readonly CountNoticesAgainst $countNoticesAgainst) {}

    /**
     * @return ModerationSignals
     */
    public function handle(int $userId): array
    {
        $notices = $this->countNoticesAgainst->handle($userId);

        return [
            'reports_received' => $notices['received'],
            'reports_open' => $notices['open'],
            'blocked_by_users' => UserBlock::query()->where('blocked_id', $userId)->count(),
        ];
    }
}
