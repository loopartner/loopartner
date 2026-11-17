<?php

namespace App\Modules\Directory\Http\Resources;

use App\Http\Resources\ServiceCategoryResource;
use App\Modules\Directory\Enums\ProjectStatus;
use App\Modules\Directory\Models\Project;
use App\Modules\Directory\Support\EarlyAccess;
use App\Support\Moderation\Restriction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Projet tel que son propriétaire le voit (/me/projects). Ni id ni user_id : seul l'uuid est public.
 *
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'description' => $this->description,
            'category' => new ServiceCategoryResource($this->whenLoaded('category')),
            'budget_min' => $this->budget_min,
            'budget_max' => $this->budget_max,
            'currency' => $this->currency,
            'country' => $this->country,
            'city' => $this->city,
            'remote_ok' => $this->remote_ok,
            'desired_start_date' => $this->desired_start_date?->toDateString(),
            'status' => $this->status->value,
            // Publié mais expiré : plus visible des prestataires, en attente de la clôture quotidienne.
            'is_expired' => $this->status === ProjectStatus::Published && ! $this->isLive(),
            'published_at' => $this->published_at?->toIso8601ZuluString(),
            'expires_at' => $this->expires_at?->toIso8601ZuluString(),
            'closed_at' => $this->closed_at?->toIso8601ZuluString(),
            // Fin de la fenêtre d'accès anticipé : jusque-là, seuls les prestataires Premium
            // voient le projet (décision 44). Null pour un projet jamais publié.
            'early_access_ends_at' => $this->status === ProjectStatus::Published ? EarlyAccess::endsAt($this->resource)?->toIso8601ZuluString() : null,
            // Masqué par la modération : invisible des prestataires jusqu'à la levée.
            'moderation' => Restriction::view($this->resource),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
