<?php

namespace App\Modules\Moderation\Listeners;

use App\Modules\Moderation\Actions\RecordModuleDecision;
use App\Support\Moderation\ContentModerated;

/**
 * Décision restrictive d'Events ou du Blog : exposé des motifs enregistré par
 * RecordModuleDecision. Synchrone (pas de ShouldQueue) : il s'exécute dans la transaction de
 * la décision.
 */
class RecordModuleDecisionOnContentModerated
{
    public function __construct(private RecordModuleDecision $recordModuleDecision) {}

    public function handle(ContentModerated $event): void
    {
        $this->recordModuleDecision->handle($event);
    }
}
