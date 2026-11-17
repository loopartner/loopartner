<?php

namespace App\Modules\Appointments\Actions;

use App\Models\User;
use App\Modules\Appointments\Models\AppointmentNote;
use App\Modules\Moderation\Actions\SubmitNotice;
use App\Modules\Moderation\Support\NoticeReceipt;
use App\Support\SecurityLog;

/**
 * Une partie signale un texte libre écrit par l'autre partie d'un rendez-vous. Le signalement
 * rejoint ceux de tous les contenus (Action publique Moderation\Actions\SubmitNotice) : la
 * modération copie le texte, et un même texte n'est signalé qu'une fois par utilisateur tant
 * qu'il est en cours d'examen (409). Son propre texte : 422 (vérifié par l'appelant). Le
 * journal security ne reçoit que des identifiants et la catégorie.
 */
class ReportAppointmentNote
{
    public function __construct(private SubmitNotice $submitNotice) {}

    /**
     * @param  array{category: string, comment?: string|null}  $data
     */
    public function handle(User $reporter, AppointmentNote $note, array $data): NoticeReceipt
    {
        $receipt = $this->submitNotice->inApp($reporter, 'appointment_note', $note->uuid, $data['category'], 'appointment_note', $data['comment'] ?? null);

        SecurityLog::record('moderation.report_created', [
            'report' => $receipt->uuid,
            'target_type' => 'appointment_note',
            'target' => $note->uuid,
            'reason' => $data['category'],
            'reported_user_id' => $note->author_id,
        ]);

        return $receipt;
    }
}
