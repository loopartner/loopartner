<?php

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRevision;
use App\Modules\Events\Support\EventContent;
use App\Modules\Events\Support\EventPresenter;
use App\Support\Moderation\Restriction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Événement tel que son organisateur le voit (/me/events) : tout le contenu, lien de connexion
 * et adresse compris, l'état de la validation et la révision en attente. Ni id ni user_id.
 *
 * Relations à charger : categories, cover, pendingRevision.cover, latestRevision.
 *
 * @mixin Event
 */
class OwnEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Event $event */
        $event = $this->resource;

        return [
            'uuid' => $event->uuid,
            'slug' => $event->slug,
            'status' => $event->status->value,
            'version' => $event->version,
            ...EventPresenter::content(EventContent::of($event), withOnlineUrl: true, withPrivateAddress: true, categories: $event->categories),
            'cover_url' => $event->cover?->url(),
            'registrations_count' => $event->registrations_count,
            'registration_limit' => $event->is_free ? $event->registrationLimit() : null,
            'submitted_at' => $event->submitted_at?->toIso8601ZuluString(),
            'published_at' => $event->published_at?->toIso8601ZuluString(),
            'rejected_at' => $event->rejected_at?->toIso8601ZuluString(),
            'rejection_reason' => $event->rejection_reason,
            'cancelled_at' => $event->cancelled_at?->toIso8601ZuluString(),
            'cancelled_by' => $event->cancelled_by?->value,
            'cancellation_reason' => $event->cancellation_reason,
            // Masqué par la modération (exposé des motifs : GET /me/moderation/decisions/{decision}).
            'moderation' => Restriction::view($event),
            'pending_revision' => $this->pendingRevision($event),
            'latest_revision' => $this->latestRevision($event),
            'created_at' => $event->created_at?->toIso8601ZuluString(),
            'updated_at' => $event->updated_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * Version proposée en attente de validation : le public voit toujours la version validée.
     *
     * @return array<string, mixed>|null
     */
    private function pendingRevision(Event $event): ?array
    {
        $revision = $event->relationLoaded('pendingRevision') ? $event->pendingRevision : null;

        if (! $revision instanceof EventRevision) {
            return null;
        }

        return [
            'uuid' => $revision->uuid,
            'version' => $revision->version,
            'submitted_at' => $revision->submitted_at->toIso8601ZuluString(),
            'changed_fields' => $revision->changed_fields,
            'content' => [
                ...EventPresenter::content($revision->payload, withOnlineUrl: true, withPrivateAddress: true),
                'cover_url' => match ($revision->coverOperation()) {
                    EventRevision::COVER_REPLACE => $revision->cover?->url(),
                    EventRevision::COVER_REMOVE => null,
                    default => $event->cover?->url(),
                },
            ],
        ];
    }

    /**
     * Dernière décision sur une révision (pour afficher un refus et son motif).
     *
     * @return array<string, mixed>|null
     */
    private function latestRevision(Event $event): ?array
    {
        $revision = $event->relationLoaded('latestRevision') ? $event->latestRevision : null;

        if (! $revision instanceof EventRevision || $revision->isPending()) {
            return null;
        }

        return [
            'uuid' => $revision->uuid,
            'status' => $revision->status->value,
            'decided_at' => $revision->decided_at?->toIso8601ZuluString(),
            'rejection_reason' => $revision->rejection_reason,
        ];
    }
}
