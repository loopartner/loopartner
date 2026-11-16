<?php

namespace App\Modules\Directory\Actions;

use App\Models\User;
use App\Modules\Directory\Enums\ReportReason;
use App\Modules\Directory\Enums\ReportTarget;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Models\Message;
use App\Modules\Moderation\Actions\SubmitNotice;
use App\Modules\Moderation\Support\NoticeReceipt;
use App\Support\SecurityLog;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Signalement d'un message ou d'une demande par son destinataire. Il rejoint les signalements de
 * tous les contenus (Action publique Moderation\Actions\SubmitNotice) : la modération copie le
 * contenu signalé (il reste consultable même s'il disparaît), et un même contenu n'est signalé
 * qu'une fois par utilisateur tant qu'il est en cours d'examen (409).
 *
 * Le journal security reçoit le motif, le type de contenu et les identifiants, jamais le
 * contenu signalé ni le commentaire.
 */
class ReportContent
{
    public function __construct(private readonly SubmitNotice $submitNotice) {}

    /**
     * @param  array{reason: string, comment?: string|null}  $data
     * @return array{receipt: NoticeReceipt, target_type: ReportTarget, reason: ReportReason}
     *
     * @throws ConflictHttpException
     */
    public function handle(User $reporter, MatchRequest|Message $target, array $data): array
    {
        [$type, $reportedUserId] = $target instanceof Message
            ? [ReportTarget::Message, $target->sender_id]
            : [ReportTarget::MatchRequest, $target->author_id];
        $reason = ReportReason::from($data['reason']);

        $receipt = $this->submitNotice->inApp($reporter, $type->value, $target->uuid, $reason->category(), $reason->value, $data['comment'] ?? null);

        SecurityLog::record('moderation.report_created', [
            'user_id' => $reporter->id,
            'report' => $receipt->uuid,
            'target_type' => $type->value,
            'target' => $target->uuid,
            'reason' => $reason->value,
            'reported_user_id' => $reportedUserId,
        ]);

        return ['receipt' => $receipt, 'target_type' => $type, 'reason' => $reason];
    }
}
