<?php

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventAnnouncement;
use App\Support\Moderation\Restriction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Annonce aux inscrits. L'organisateur voit aussi son statut, le motif d'un refus et le nombre
 * d'inscrits prévenus ; un inscrit ne voit que les annonces publiées (texte et date).
 *
 * @mixin EventAnnouncement
 */
class AnnouncementResource extends JsonResource
{
    private bool $forOrganizer = false;

    public function forOrganizer(): self
    {
        $this->forOrganizer = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var EventAnnouncement $announcement */
        $announcement = $this->resource;

        $public = [
            'uuid' => $announcement->uuid,
            'body' => $announcement->body,
            'published_at' => $announcement->published_at?->toIso8601ZuluString(),
        ];

        if (! $this->forOrganizer) {
            return $public;
        }

        return [
            ...$public,
            'status' => $announcement->status->value,
            'has_links' => $announcement->has_links,
            'recipients_count' => $announcement->recipients_count,
            'rejection_reason' => $announcement->rejection_reason,
            'moderation' => Restriction::view($announcement),
            'created_at' => $announcement->created_at?->toIso8601ZuluString(),
        ];
    }
}
