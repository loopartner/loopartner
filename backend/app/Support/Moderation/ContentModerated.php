<?php

namespace App\Support\Moderation;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Événement du noyau : une décision restrictive vient d'être prise dans la modération propre à
 * un module (refus d'un événement, d'une révision ou d'une annonce, annulation par un admin ;
 * refus ou dépublication d'un article, refus d'une révision). Le module Moderation en tire un
 * exposé des motifs consultable et contestable (DSA art. 17 et recours interne), sans changer
 * le fonctionnement du module : ses effets (statut, nouvelle soumission) restent gérés par lui.
 *
 * Envoyé de façon synchrone dans la transaction de la décision : si l'enregistrement échoue,
 * la décision est annulée.
 *
 * - type : type de contenu signalable (event, event_announcement, article) ;
 * - ref : slug public s'il existe, sinon uuid ;
 * - action : refused (avant publication) ou withdrawn (contenu publié retiré).
 */
final class ContentModerated
{
    use Dispatchable;

    public const REFUSED = 'refused';

    public const WITHDRAWN = 'withdrawn';

    public function __construct(
        public readonly string $type,
        public readonly string $ref,
        public readonly ?int $ownerId,
        public readonly string $label,
        public readonly string $reason,
        public readonly int $moderatorId,
        public readonly string $action,
    ) {}
}
