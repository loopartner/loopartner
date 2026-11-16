<?php

namespace App\Modules\Directory\Http\Resources;

use App\Modules\Directory\Enums\ReportReason;
use App\Modules\Directory\Enums\ReportTarget;
use App\Modules\Moderation\Support\NoticeReceipt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Accusé de réception d'un signalement de la messagerie : ni la copie du contenu signalé, ni
 * l'utilisateur signalé, ni aucun identifiant interne. Le suivi se fait ensuite dans
 * « Mes signalements » (GET /me/moderation/notices/{uuid}).
 *
 * @property array{receipt: NoticeReceipt, target_type: ReportTarget, reason: ReportReason} $resource
 */
class AbuseReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $receipt = $this->resource['receipt'];

        return [
            'uuid' => $receipt->uuid,
            'target_type' => $this->resource['target_type']->value,
            'reason' => $this->resource['reason']->value,
            'status' => $receipt->status,
            'created_at' => $receipt->createdAt?->toIso8601ZuluString(),
        ];
    }
}
