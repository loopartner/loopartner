<?php

namespace App\Modules\Moderation\Actions;

use App\Models\User;
use App\Modules\Moderation\Enums\DecisionGround;
use App\Modules\Moderation\Enums\DecisionMeasure;
use App\Modules\Moderation\Enums\DecisionSource;
use App\Modules\Moderation\Enums\NoticeStatus;
use App\Modules\Moderation\Models\Decision;
use App\Modules\Moderation\Models\Notice;
use App\Modules\Moderation\Support\DecisionMailer;
use App\Support\AdminAudit;
use App\Support\Moderation\ModerationTargets;
use App\Support\Moderation\VersionGuard;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Décision d'un admin sur un contenu (DSA art. 17), après des signalements ou de sa propre
 * initiative. La décision traite tous les signalements en cours sur ce contenu.
 *
 * - Restriction : le contenu doit exister et être visible ; l'admin envoie l'empreinte de la
 *   version qu'il a lue (409 sinon) ; il ne décide jamais sur son propre contenu (403).
 * - Aucune mesure : seulement s'il y a des signalements en cours (409 sinon) ; un signalement
 *   peut être marqué « manifestement infondé » (statistique, sans effet sur le signalant).
 *
 * Exposé des motifs à l'auteur du contenu (restriction), décision aux signalants, journal
 * d'audit (faits), canal security (identifiants seulement), après la validation de la
 * transaction.
 */
class DecideOnContent
{
    /**
     * @param  array{measure: string, ground?: string|null, category?: string|null, legal_reference?: string|null, terms_clause?: string|null, facts: string, correction_hint?: string|null, version?: string|null, manifestly_unfounded?: bool|null}  $data
     *
     * @throws ConflictHttpException|AccessDeniedHttpException
     */
    public function handle(User $admin, string $type, string $ref, array $data, ?Request $request = null): Decision
    {
        $measure = DecisionMeasure::from($data['measure']);
        $target = ModerationTargets::get($type);

        [$decision, $notices] = DB::transaction(function () use ($admin, $type, $ref, $data, $measure, $target): array {
            $notices = Notice::query()
                ->where('target_type', $type)
                ->where('target_ref', $ref)
                ->where('status', NoticeStatus::Open->value)
                ->lockForUpdate()
                ->get();
            $content = $target->inspect($ref);

            if ($content !== null && $content->ownerId !== null && $content->ownerId === $admin->id) {
                SecurityLog::record('moderation.self_moderation_denied', ['user_id' => $admin->id, 'target_type' => $type]);

                throw new AccessDeniedHttpException('Vous ne pouvez pas décider sur votre propre contenu.');
            }

            if ($measure->restricts()) {
                if ($content === null) {
                    throw new ConflictHttpException('Ce contenu n\'existe plus : aucune restriction n\'est possible.');
                }

                if ($content->isRestricted()) {
                    throw new ConflictHttpException('Ce contenu est déjà masqué.');
                }
            } elseif ($notices->isEmpty()) {
                throw new ConflictHttpException('Aucun signalement en cours sur ce contenu.');
            }

            if ($content !== null) {
                VersionGuard::assertSameFingerprint((string) ($data['version'] ?? ''), $content->version);
            }

            /** @var Notice|null $first */
            $first = $notices->first();
            $decision = new Decision;
            $decision->forceFill([
                'uuid' => (string) Str::uuid(),
                'target_type' => $type,
                'target_ref' => $content !== null ? $content->ref : $ref,
                'owner_id' => $content !== null ? $content->ownerId : $first?->target_owner_id,
                'target_label' => $content !== null ? $content->label : $first?->content_label,
                'measure' => $measure,
                'ground' => $measure->restricts() ? DecisionGround::from((string) $data['ground']) : null,
                'category' => $measure->restricts() ? $data['category'] : null,
                'legal_reference' => $data['legal_reference'] ?? null,
                'terms_clause' => $data['terms_clause'] ?? null,
                'facts' => $data['facts'],
                'correction_hint' => $data['correction_hint'] ?? null,
                'source' => $notices->isEmpty() ? DecisionSource::OwnInitiative : DecisionSource::Notice,
                'automated' => false,
                'reversible' => true,
                'content_version' => $content?->version,
                'decided_by' => $admin->id,
                'decided_at' => now(),
                'appeal_deadline_at' => now()->addMonths((int) config('moderation.appeal_window_months')),
            ])->save();

            if ($measure->restricts()) {
                $target->restrict($decision->target_ref, $decision->uuid);
            }

            Notice::query()->whereKey($notices->modelKeys())->update([
                'status' => NoticeStatus::Decided->value,
                'decision_id' => $decision->id,
                'closed_at' => now(),
                'closed_by' => $admin->id,
                'active_key' => null,
                'manifestly_unfounded' => ! $measure->restricts() && (bool) ($data['manifestly_unfounded'] ?? false),
                'updated_at' => now(),
            ]);

            return [$decision, $notices];
        });

        AdminAudit::record($admin, 'moderation.decision.'.($measure->restricts() ? 'restricted' : 'no_action'), 'moderation_decision', $decision->uuid, null, $decision->facts, $request);
        SecurityLog::record('moderation.decision_taken', [
            'user_id' => $admin->id,
            'decision' => $decision->uuid,
            'target_type' => $type,
            'measure' => $measure->value,
            'ground' => $decision->ground?->value,
            'category' => $decision->category,
            'source' => $decision->source->value,
            'notices' => $notices->count(),
        ], request: $request);

        if ($measure->restricts()) {
            DecisionMailer::statementToOwner($decision);
        }

        foreach ($notices as $notice) {
            DecisionMailer::decisionToNotifier($notice, $decision);
        }

        return $decision;
    }
}
