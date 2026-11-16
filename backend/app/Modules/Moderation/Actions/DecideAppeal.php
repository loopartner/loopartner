<?php

namespace App\Modules\Moderation\Actions;

use App\Models\User;
use App\Modules\Moderation\Enums\AppealStatus;
use App\Modules\Moderation\Enums\AppellantKind;
use App\Modules\Moderation\Enums\NoticeStatus;
use App\Modules\Moderation\Models\Appeal;
use App\Modules\Moderation\Models\Notice;
use App\Modules\Moderation\Support\DecisionMailer;
use App\Modules\Moderation\Support\Keys;
use App\Support\AdminAudit;
use App\Support\SecurityLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Décision motivée d'un admin sur un recours (DSA art. 20 §4 à §6), jamais automatisée.
 *
 * - Accepté, recours de l'auteur : la restriction est levée (le contenu redevient visible).
 *   Pour une décision de la modération avant publication (Events, Blog), le contenu reste géré
 *   par son module : l'auteur le soumet de nouveau et il est réexaminé.
 * - Accepté, recours du signalant : son signalement est rouvert et réexaminé.
 * - Rejeté : la décision est maintenue.
 *
 * L'admin ne tranche pas un recours sur son propre contenu (403) ; si
 * moderation.require_distinct_appeal_reviewer est activé, ni un recours contre sa propre
 * décision (403). L'auteur du recours reçoit la décision et son motif, avec la possibilité de
 * saisir la justice.
 */
class DecideAppeal
{
    public function __construct(private readonly LiftDecision $liftDecision) {}

    /**
     * @throws ConflictHttpException|AccessDeniedHttpException
     */
    public function handle(User $admin, Appeal $appeal, bool $upheld, string $reason, ?Request $request = null): Appeal
    {
        [$appeal, $outcome] = DB::transaction(function () use ($admin, $appeal, $upheld, $reason, $request): array {
            $appeal = Appeal::query()->whereKey($appeal->id)->lockForUpdate()->with(['decision', 'notice'])->firstOrFail();
            $decision = $appeal->decision;

            if (! $appeal->isPending()) {
                throw new ConflictHttpException('Ce recours a déjà été examiné.');
            }

            if ($decision->owner_id !== null && $decision->owner_id === $admin->id) {
                SecurityLog::record('moderation.self_moderation_denied', ['user_id' => $admin->id, 'appeal' => $appeal->uuid]);

                throw new AccessDeniedHttpException('Vous ne pouvez pas examiner un recours sur votre propre contenu.');
            }

            if (config('moderation.require_distinct_appeal_reviewer') === true && $decision->decided_by === $admin->id) {
                SecurityLog::record('moderation.appeal_self_review_denied', ['user_id' => $admin->id, 'appeal' => $appeal->uuid]);

                throw new AccessDeniedHttpException('Un autre admin doit examiner le recours contre votre décision.');
            }

            $outcome = 'rejected';

            if ($upheld && $appeal->appellant_kind === AppellantKind::Owner) {
                $outcome = $decision->reversible ? 'upheld' : 'upheld_review';

                if ($decision->reversible && $decision->isActiveRestriction()) {
                    $this->liftDecision->handle($admin, $decision, $reason, $request, notify: false);
                }
            } elseif ($upheld) {
                $outcome = 'upheld_notifier';
                $this->reopen($appeal->notice);
            }

            $appeal->forceFill([
                'status' => $upheld ? AppealStatus::Upheld : AppealStatus::Rejected,
                'decided_by' => $admin->id,
                'decided_at' => now(),
                'outcome_reason' => $reason,
            ])->save();

            return [$appeal, $outcome];
        });

        AdminAudit::record($admin, 'moderation.appeal.'.($upheld ? 'upheld' : 'rejected'), 'moderation_appeal', $appeal->uuid, null, $reason, $request);
        SecurityLog::record('moderation.appeal_decided', [
            'user_id' => $admin->id,
            'appeal' => $appeal->uuid,
            'decision' => $appeal->decision->uuid,
            'outcome' => $outcome,
        ], request: $request);

        $appellant = $appeal->appellant_id !== null ? User::query()->find($appeal->appellant_id) : null;
        DecisionMailer::appealOutcome(
            $appellant,
            $appeal->appellant_kind === AppellantKind::Notifier ? $appeal->notice : null,
            $appeal->decision,
            $outcome,
            $reason,
        );

        return $appeal;
    }

    /**
     * Le signalement redevient ouvert, avec un nouvel objectif de traitement.
     */
    private function reopen(?Notice $notice): void
    {
        if ($notice === null) {
            return;
        }

        $reporterKey = $notice->reporter_id !== null
            ? 'user:'.$notice->reporter_id
            : ($notice->reporter_email_hash !== null ? 'email:'.$notice->reporter_email_hash : null);

        $notice->forceFill([
            'status' => NoticeStatus::Open,
            'decision_id' => null,
            'closed_at' => null,
            'closed_by' => null,
            'manifestly_unfounded' => false,
            'due_at' => now()->addHours($notice->priority->processingHours()),
        ])->save();

        if ($reporterKey === null) {
            return;
        }

        try {
            DB::transaction(fn () => $notice->forceFill(['active_key' => Keys::active($notice->target_type, $notice->target_ref, $reporterKey)])->save());
        } catch (UniqueConstraintViolationException) {
            // Le signalant a déjà un autre signalement en cours sur ce contenu : rien à ajouter.
        }
    }
}
