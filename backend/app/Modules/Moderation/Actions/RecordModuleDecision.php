<?php

namespace App\Modules\Moderation\Actions;

use App\Modules\Moderation\Enums\DecisionGround;
use App\Modules\Moderation\Enums\DecisionMeasure;
use App\Modules\Moderation\Enums\DecisionSource;
use App\Modules\Moderation\Models\Decision;
use App\Support\Moderation\ContentModerated;
use Illuminate\Support\Str;

/**
 * Exposé des motifs d'une décision prise dans la modération d'Events ou du Blog (événement du
 * noyau ContentModerated), pour que l'auteur la retrouve dans « Mes décisions » et puisse la
 * contester comme toute autre décision (DSA art. 17, recours interne).
 *
 * Le module garde la main sur le contenu : la décision n'est pas réversible ici (reversible =
 * false) ; un recours accepté invite l'auteur à soumettre de nouveau son contenu. Le motif saisi
 * par le modérateur devient les faits ; le fondement est la clause des CGU prévue pour la
 * modération avant publication (config moderation.pre_publication_clause).
 *
 * L'email reste celui du module (avec un lien vers l'exposé des motifs) : aucun email ici.
 */
class RecordModuleDecision
{
    public function handle(ContentModerated $event): Decision
    {
        $decision = new Decision;
        $decision->forceFill([
            'uuid' => (string) Str::uuid(),
            'target_type' => $event->type,
            'target_ref' => $event->ref,
            'owner_id' => $event->ownerId,
            'target_label' => $event->label,
            'measure' => DecisionMeasure::RestrictVisibility,
            'ground' => DecisionGround::Terms,
            'category' => 'terms',
            'terms_clause' => (string) config('moderation.pre_publication_clause'),
            'facts' => $event->reason,
            'source' => $event->action === ContentModerated::WITHDRAWN ? DecisionSource::OwnInitiative : DecisionSource::PrePublicationReview,
            'automated' => false,
            'reversible' => false,
            'decided_by' => $event->moderatorId,
            'decided_at' => now(),
            'appeal_deadline_at' => now()->addMonths((int) config('moderation.appeal_window_months')),
        ])->save();

        return $decision;
    }
}
