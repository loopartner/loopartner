<?php

namespace App\Modules\Directory\Actions;

use App\Modules\Directory\Enums\ReportStatus;
use App\Modules\Directory\Models\AbuseReport;
use App\Modules\Directory\Models\UserBlock;

/**
 * Action publique du module Directory : signaux de modération sur un utilisateur, pour
 * l'historique présenté aux admins par d'autres modules (Events : vérification d'un
 * organisateur), sans exposer les modèles AbuseReport ni UserBlock.
 *
 * Seulement des nombres : ni contenu signalé, ni commentaire, ni identité de qui a signalé ou
 * bloqué. Lecture seule.
 *
 * @phpstan-type ModerationSignals array{reports_received: int, reports_open: int, blocked_by_users: int}
 */
class GetUserModerationSignals
{
    /**
     * @return ModerationSignals
     */
    public function handle(int $userId): array
    {
        $reports = AbuseReport::query()->where('reported_user_id', $userId);

        return [
            'reports_received' => (clone $reports)->count(),
            'reports_open' => (clone $reports)->where('status', ReportStatus::Open->value)->count(),
            'blocked_by_users' => UserBlock::query()->where('blocked_id', $userId)->count(),
        ];
    }
}
