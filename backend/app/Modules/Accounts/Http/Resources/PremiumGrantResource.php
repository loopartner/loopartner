<?php

namespace App\Modules\Accounts\Http\Resources;

use App\Modules\Accounts\Models\PremiumGrant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Période premium vue par un admin. Ni identifiant interne, ni admin, ni motif (le motif est
 * dans le journal d'audit).
 *
 * @mixin PremiumGrant
 */
class PremiumGrantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'source' => $this->source->value,
            'starts_at' => $this->starts_at->toIso8601ZuluString(),
            'ends_at' => $this->ends_at?->toIso8601ZuluString(),
            'active' => $this->isActive(),
            'revoked_at' => $this->revoked_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
