<?php

namespace App\Modules\Moderation\Actions;

use App\Models\User;
use App\Modules\Moderation\Models\Decision;
use App\Modules\Moderation\Support\DecisionMailer;
use App\Support\AdminAudit;
use App\Support\Moderation\ModerationTargets;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Levée d'une restriction par un admin (correction de l'équipe, ou recours accepté via
 * DecideAppeal) : le contenu redevient visible tel qu'il était. Seule une restriction en vigueur
 * et réversible se lève (409 sinon) : une décision prise dans Events ou le Blog reste gérée par
 * ce module. L'auteur du contenu est prévenu, avec le motif.
 */
class LiftDecision
{
    /**
     * @throws ConflictHttpException
     */
    public function handle(User $admin, Decision $decision, string $reason, ?Request $request = null, bool $notify = true): Decision
    {
        $decision = DB::transaction(function () use ($admin, $decision, $reason): Decision {
            $decision = Decision::query()->whereKey($decision->id)->lockForUpdate()->firstOrFail();

            if (! $decision->reversible) {
                throw new ConflictHttpException('Cette décision a été prise dans la modération avant publication : ses effets se gèrent dans ce module.');
            }

            if (! $decision->isActiveRestriction()) {
                throw new ConflictHttpException('Cette décision n\'est pas une restriction en vigueur.');
            }

            if (ModerationTargets::has($decision->target_type)) {
                ModerationTargets::get($decision->target_type)->lift($decision->target_ref, $decision->uuid);
            }

            $decision->forceFill([
                'lifted_at' => now(),
                'lifted_by' => $admin->id,
                'lift_reason' => $reason,
            ])->save();

            return $decision;
        });

        AdminAudit::record($admin, 'moderation.decision.lifted', 'moderation_decision', $decision->uuid, null, $reason, $request);
        SecurityLog::record('moderation.restriction_lifted', [
            'user_id' => $admin->id,
            'decision' => $decision->uuid,
            'target_type' => $decision->target_type,
        ], request: $request);

        if ($notify) {
            DecisionMailer::liftToOwner($decision, $reason);
        }

        return $decision;
    }
}
