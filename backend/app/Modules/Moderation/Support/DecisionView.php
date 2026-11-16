<?php

namespace App\Modules\Moderation\Support;

use App\Modules\Moderation\Enums\AppealStatus;
use App\Modules\Moderation\Enums\AppellantKind;
use App\Modules\Moderation\Models\Appeal;
use App\Modules\Moderation\Models\Decision;

/**
 * Présentation d'une décision : l'exposé des motifs complet pour l'auteur du contenu (DSA
 * art. 17 §3), un résumé pour le signalant (art. 16 §5 : la décision et les voies de recours,
 * sans les faits, qui peuvent concerner l'auteur).
 */
final class DecisionView
{
    /**
     * @return array<string, mixed>
     */
    public static function forNotifier(Decision $decision, string $locale): array
    {
        return [
            'measure' => $decision->measure->value,
            ...self::ground($decision, $locale),
            'decided_at' => $decision->decided_at->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forOwner(Decision $decision, string $locale): array
    {
        return [
            'uuid' => $decision->uuid,
            'target' => [
                'type' => $decision->target_type,
                'type_label' => TargetTypes::label($decision->target_type, $locale),
                'ref' => $decision->target_ref,
                'label' => $decision->target_label,
            ],
            'measure' => $decision->measure->value,
            ...self::ground($decision, $locale),
            'facts' => $decision->facts,
            'correction_hint' => $decision->correction_hint,
            'source' => $decision->source->value,
            'automated' => $decision->automated,
            // Portée : partout où le service est proposé ; durée : jusqu'à la levée.
            'territorial_scope' => 'all',
            'ends_at' => null,
            'decided_at' => $decision->decided_at->toIso8601ZuluString(),
            'lifted_at' => $decision->lifted_at?->toIso8601ZuluString(),
            'lift_reason' => $decision->lift_reason,
            'redress' => [
                'internal_appeal' => self::appealState($decision, $decision->appeals->where('appellant_kind', AppellantKind::Owner)),
                'court' => true,
            ],
            'appeals' => self::appeals($decision->appeals->where('appellant_kind', AppellantKind::Owner)),
        ];
    }

    /**
     * État du recours d'une personne : ouvert ou non, date limite, recours en attente.
     *
     * @param  iterable<Appeal>  $appeals  recours de cette personne contre cette décision
     * @return array{available: bool, deadline_at: string|null, pending: bool}
     */
    public static function appealState(Decision $decision, iterable $appeals, bool $eligible = true): array
    {
        $count = 0;
        $pending = false;

        foreach ($appeals as $appeal) {
            $count++;
            $pending = $pending || $appeal->status === AppealStatus::Pending;
        }

        return [
            'available' => $eligible
                && $decision->appealOpen()
                && ! $pending
                && $count < (int) config('moderation.max_appeals_per_decision'),
            'deadline_at' => $decision->appeal_deadline_at?->toIso8601ZuluString(),
            'pending' => $pending,
        ];
    }

    /**
     * @param  iterable<Appeal>  $appeals
     * @return list<array<string, mixed>>
     */
    public static function appeals(iterable $appeals): array
    {
        $list = [];

        foreach ($appeals as $appeal) {
            $list[] = [
                'uuid' => $appeal->uuid,
                'status' => $appeal->status->value,
                'created_at' => $appeal->created_at?->toIso8601ZuluString(),
                'decided_at' => $appeal->decided_at?->toIso8601ZuluString(),
                'outcome_reason' => $appeal->outcome_reason,
            ];
        }

        return $list;
    }

    /**
     * @return array{ground: string|null, category: array{key: string, label: string}|null, legal_reference: string|null, terms_clause: array{key: string, reference: string|null, label: string}|null}
     */
    private static function ground(Decision $decision, string $locale): array
    {
        return [
            'ground' => $decision->ground?->value,
            'category' => $decision->category === null ? null : [
                'key' => $decision->category,
                'label' => Categories::label($decision->category, $locale),
            ],
            'legal_reference' => $decision->legal_reference,
            'terms_clause' => TermsClauses::describe($decision->terms_clause, $locale),
        ];
    }
}
