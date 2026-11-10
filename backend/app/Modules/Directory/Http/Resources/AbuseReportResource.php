<?php

namespace App\Modules\Directory\Http\Resources;

use App\Modules\Directory\Models\AbuseReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Accusé de réception d'un signalement : ni la copie du contenu signalé, ni l'utilisateur
 * signalé, ni aucun identifiant interne.
 *
 * @mixin AbuseReport
 */
class AbuseReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'target_type' => $this->target_type->value,
            'reason' => $this->reason->value,
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
