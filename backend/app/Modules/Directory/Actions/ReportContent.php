<?php

namespace App\Modules\Directory\Actions;

use App\Models\User;
use App\Modules\Directory\Enums\ReportReason;
use App\Modules\Directory\Enums\ReportStatus;
use App\Modules\Directory\Enums\ReportTarget;
use App\Modules\Directory\Models\AbuseReport;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Models\Message;
use App\Support\SecurityLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Enregistre le signalement d'un message ou d'une demande, pour la modération (back-office à
 * venir). Le contenu signalé est copié (content_snapshot) : il reste consultable par la
 * modération même s'il disparaît ensuite. Un même contenu n'est signalé qu'une fois par
 * utilisateur (409).
 *
 * Le journal security reçoit le motif, le type de contenu et les identifiants, jamais le
 * contenu signalé ni le commentaire.
 */
class ReportContent
{
    /**
     * @param  array{reason: string, comment?: string|null}  $data
     *
     * @throws ConflictHttpException
     */
    public function handle(User $reporter, MatchRequest|Message $target, array $data): AbuseReport
    {
        [$type, $reportedUserId, $snapshot] = $target instanceof Message
            ? [ReportTarget::Message, $target->sender_id, $target->body]
            : [ReportTarget::MatchRequest, $target->author_id, $target->message];

        $report = new AbuseReport([
            'reason' => ReportReason::from($data['reason']),
            'comment' => $data['comment'] ?? null,
        ]);

        $report->forceFill([
            'uuid' => (string) Str::uuid(),
            'reporter_id' => $reporter->id,
            'reported_user_id' => $reportedUserId,
            'target_type' => $type,
            'target_id' => $target->id,
            'content_snapshot' => $snapshot,
            'status' => ReportStatus::Open,
        ]);

        try {
            $report->save();
        } catch (UniqueConstraintViolationException) {
            throw new ConflictHttpException('Vous avez déjà signalé ce contenu.');
        }

        SecurityLog::record('moderation.report_created', [
            'user_id' => $reporter->id,
            'report' => $report->uuid,
            'target_type' => $type->value,
            'target' => $target->uuid,
            'reason' => $report->reason->value,
            'reported_user_id' => $reportedUserId,
        ]);

        return $report;
    }
}
