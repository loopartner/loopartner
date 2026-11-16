<?php

namespace App\Modules\Moderation\Support;

use Carbon\CarbonInterface;

/**
 * Accusé de réception d'un signalement remis à un autre module (Directory), sans le modèle :
 * uuid, état simplifié ("open" tant qu'il est en cours d'examen, "closed" ensuite) et date.
 */
final readonly class NoticeReceipt
{
    public function __construct(
        public string $uuid,
        public string $status,
        public ?CarbonInterface $createdAt,
    ) {}
}
