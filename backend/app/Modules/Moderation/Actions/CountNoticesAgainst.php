<?php

namespace App\Modules\Moderation\Actions;

use App\Modules\Moderation\Enums\NoticeStatus;
use App\Modules\Moderation\Models\Notice;

/**
 * Action publique du module Moderation : nombre de signalements reçus par les contenus d'un
 * utilisateur, et combien sont encore en cours d'examen. Seulement des nombres (ni contenu,
 * ni signalant). Lecture seule ; utilisée par Directory (GetUserModerationSignals).
 */
class CountNoticesAgainst
{
    /**
     * @return array{received: int, open: int}
     */
    public function handle(int $userId): array
    {
        $notices = Notice::query()->where('target_owner_id', $userId);

        return [
            'received' => (clone $notices)->count(),
            'open' => (clone $notices)->where('status', NoticeStatus::Open->value)->count(),
        ];
    }
}
