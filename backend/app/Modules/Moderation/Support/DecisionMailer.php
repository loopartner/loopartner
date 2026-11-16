<?php

namespace App\Modules\Moderation\Support;

use App\Models\User;
use App\Modules\Moderation\Actions\SubmitNotice;
use App\Modules\Moderation\Enums\ModerationMail;
use App\Modules\Moderation\Models\Decision;
use App\Modules\Moderation\Models\Notice;
use App\Modules\Moderation\Notifications\ModerationNotification;

/**
 * Emails liés aux décisions (partis après la validation de la transaction) :
 *
 * - à l'auteur du contenu : l'exposé des motifs complet (DSA art. 17), sa levée ;
 * - au signalant : la décision et ses voies de recours (art. 16 §5), sans les faits ;
 * - à l'auteur d'un recours : son issue.
 *
 * Un signalant sans compte reçoit un nouveau jeton de suivi (l'ancien cesse de fonctionner) :
 * seul son empreinte est en base, l'email est le seul endroit où il apparaît.
 */
final class DecisionMailer
{
    public static function statementToOwner(Decision $decision): void
    {
        $owner = $decision->owner_id !== null ? User::query()->find($decision->owner_id) : null;

        if ($owner === null) {
            return;
        }

        ModerationNotification::toUser($owner, ModerationMail::DecisionTaken, 'moderation.decisions', [
            'uuid' => $decision->uuid,
            'type' => $decision->target_type,
            'label' => $decision->target_label,
            'source' => $decision->source->value,
            'ground' => $decision->ground?->value,
            'law' => $decision->legal_reference,
            'clause' => $decision->terms_clause,
            'facts' => $decision->facts,
            'hint' => $decision->correction_hint,
            'deadline' => $decision->appeal_deadline_at?->toIso8601ZuluString(),
            'path' => self::decisionPath($decision),
        ]);
    }

    public static function liftToOwner(Decision $decision, ?string $reason): void
    {
        $owner = $decision->owner_id !== null ? User::query()->find($decision->owner_id) : null;

        if ($owner === null) {
            return;
        }

        ModerationNotification::toUser($owner, ModerationMail::RestrictionLifted, 'moderation.decisions', [
            'uuid' => $decision->uuid,
            'type' => $decision->target_type,
            'label' => $decision->target_label,
            'reason' => $reason,
            'path' => self::decisionPath($decision),
        ]);
    }

    public static function decisionToNotifier(Notice $notice, Decision $decision): void
    {
        self::toNotifier($notice, ModerationMail::NoticeDecided, [
            'uuid' => $notice->uuid,
            'type' => $notice->target_type,
            'measure' => $decision->measure->value,
            'ground' => $decision->ground?->value,
            'law' => $decision->legal_reference,
            'clause' => $decision->terms_clause,
            // Recours du signalant : seulement contre une absence de mesure.
            'deadline' => $decision->measure->restricts() ? null : $decision->appeal_deadline_at?->toIso8601ZuluString(),
        ]);
    }

    /**
     * @param  'upheld'|'upheld_review'|'upheld_notifier'|'rejected'  $outcome
     */
    public static function appealOutcome(?User $appellant, ?Notice $notice, Decision $decision, string $outcome, ?string $reason): void
    {
        $data = [
            'uuid' => $decision->uuid,
            'type' => $decision->target_type,
            'outcome' => $outcome,
            'reason' => $reason,
        ];

        if ($notice !== null) {
            self::toNotifier($notice, ModerationMail::AppealDecided, $data);

            return;
        }

        if ($appellant !== null) {
            ModerationNotification::toUser($appellant, ModerationMail::AppealDecided, 'moderation.decisions', [
                ...$data,
                'path' => self::decisionPath($decision),
            ]);
        }
    }

    public static function decisionPath(Decision $decision): string
    {
        return '/me/moderation/decisions/'.$decision->uuid;
    }

    /**
     * @param  array<string, string|null>  $data
     */
    private static function toNotifier(Notice $notice, ModerationMail $mail, array $data): void
    {
        if ($notice->reporter_id !== null) {
            $reporter = User::query()->find($notice->reporter_id);

            if ($reporter !== null) {
                ModerationNotification::toUser($reporter, $mail, 'moderation.notices', [
                    ...$data,
                    'path' => '/me/moderation/notices/'.$notice->uuid,
                ]);
            }

            return;
        }

        if ($notice->reporter_email === null) {
            return;
        }

        [$token, $hash] = TrackingToken::generate();
        $notice->forceFill(['tracking_token_hash' => $hash])->save();

        ModerationNotification::toAddress($notice->reporter_email, $notice->reporter_locale, $mail, 'moderation.notices', [
            ...$data,
            'path' => SubmitNotice::trackingPath($notice->uuid, $token),
        ]);
    }
}
