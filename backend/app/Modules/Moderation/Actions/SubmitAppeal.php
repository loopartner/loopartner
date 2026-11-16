<?php

namespace App\Modules\Moderation\Actions;

use App\Models\User;
use App\Modules\Moderation\Enums\AppealStatus;
use App\Modules\Moderation\Enums\AppellantKind;
use App\Modules\Moderation\Models\Appeal;
use App\Modules\Moderation\Models\Decision;
use App\Modules\Moderation\Models\Notice;
use App\Modules\Moderation\Support\Keys;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Recours interne contre une décision (aligné sur DSA art. 20, décision 21) : gratuit, par voie
 * électronique, ouvert pendant moderation.appeal_window_months après la décision.
 *
 * - L'auteur du contenu conteste une restriction en vigueur ; après une correction, c'est aussi
 *   la voie pour demander le réexamen (décision 23).
 * - Le signalant conteste une décision qui n'a pris aucune mesure.
 * - Un seul recours en attente à la fois par personne et par décision, au plus
 *   moderation.max_appeals_per_decision au total (409 au-delà).
 *
 * Le journal security ne reçoit que des identifiants, jamais le texte du recours.
 */
class SubmitAppeal
{
    /**
     * @throws ConflictHttpException
     */
    public function byOwner(User $owner, Decision $decision, string $statement): Appeal
    {
        return $this->create($decision, AppellantKind::Owner, Keys::appellant('owner:'.$owner->id), $owner, null, $statement, function (Decision $decision): void {
            if (! $decision->measure->restricts()) {
                throw new ConflictHttpException('Cette décision n\'a pris aucune mesure sur votre contenu.');
            }

            if ($decision->lifted_at !== null) {
                throw new ConflictHttpException('Cette décision a déjà été levée.');
            }
        });
    }

    /**
     * @throws ConflictHttpException
     */
    public function byNotifier(Notice $notice, ?User $notifier, string $statement): Appeal
    {
        $decision = $notice->decision ?? throw new ConflictHttpException('Votre signalement n\'a pas encore fait l\'objet d\'une décision.');

        return $this->create($decision, AppellantKind::Notifier, Keys::appellant('notice:'.$notice->uuid), $notifier, $notice, $statement, function (Decision $decision): void {
            if ($decision->measure->restricts()) {
                throw new ConflictHttpException('Le contenu signalé a déjà été masqué : il n\'y a rien à contester.');
            }
        });
    }

    /**
     * @param  callable(Decision): void  $eligible
     */
    private function create(Decision $decision, AppellantKind $kind, string $key, ?User $appellant, ?Notice $notice, string $statement, callable $eligible): Appeal
    {
        $appeal = DB::transaction(function () use ($decision, $kind, $key, $appellant, $notice, $statement, $eligible): Appeal {
            $decision = Decision::query()->whereKey($decision->id)->lockForUpdate()->firstOrFail();
            $eligible($decision);

            if (! $decision->appealOpen()) {
                throw new ConflictHttpException('Le délai de recours contre cette décision est expiré.');
            }

            $previous = Appeal::query()->where('decision_id', $decision->id)->where('appellant_key', $key);

            if ((clone $previous)->where('status', AppealStatus::Pending->value)->exists()) {
                throw new ConflictHttpException('Un recours est déjà en cours d\'examen.');
            }

            if ((clone $previous)->count() >= (int) config('moderation.max_appeals_per_decision')) {
                throw new ConflictHttpException('Vous avez atteint le nombre maximal de recours contre cette décision.');
            }

            $appeal = new Appeal;
            $appeal->forceFill([
                'uuid' => (string) Str::uuid(),
                'decision_id' => $decision->id,
                'notice_id' => $notice?->id,
                'appellant_kind' => $kind,
                'appellant_id' => $appellant?->id,
                'appellant_key' => $key,
                'statement' => $statement,
                'status' => AppealStatus::Pending,
            ])->save();

            return $appeal;
        });

        SecurityLog::record('moderation.appeal_submitted', [
            'user_id' => $appellant?->id,
            'appeal' => $appeal->uuid,
            'decision' => $decision->uuid,
            'appellant_kind' => $kind->value,
        ]);

        return $appeal;
    }
}
