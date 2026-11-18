<?php

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Enums\AnnouncementStatus;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\EventAnnouncement;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Support\EventContent;
use App\Modules\Events\Support\EventPresenter;
use App\Modules\Events\Support\OrganizerDirectory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Inscription de l'utilisateur connecté, avec l'événement tel qu'un inscrit le voit : adresse
 * complète, et lien de connexion tant que l'événement est publié et pas terminé (c'est ici, et
 * non dans les emails, que l'inscrit le trouve). Le détail ajoute les annonces publiées.
 *
 * Relations à charger : event.categories, event.cover, event.user.roles (et
 * event.announcements pour le détail).
 *
 * @mixin EventRegistration
 */
class MyRegistrationResource extends JsonResource
{
    private bool $withAnnouncements = false;

    public function __construct(EventRegistration $registration, private OrganizerDirectory $organizers)
    {
        parent::__construct($registration);
    }

    public function withAnnouncements(): self
    {
        $this->withAnnouncements = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var EventRegistration $registration */
        $registration = $this->resource;
        $event = $registration->event;
        $content = EventPresenter::content(EventContent::of($event), withOnlineUrl: $event->isLive(), withPrivateAddress: true, categories: $event->categories);
        unset($content['organizer_name']);

        return [
            'uuid' => $registration->uuid,
            'registered_at' => $registration->created_at?->toIso8601ZuluString(),
            'event' => [
                'slug' => $event->slug,
                'status' => match (true) {
                    $event->status === EventStatus::Cancelled => 'cancelled',
                    $event->hasEnded() => 'past',
                    default => 'published',
                },
                ...$content,
                'cover_url' => $event->cover?->url(),
                'organizer' => $this->organizers->present($event),
                'cancelled_at' => $event->cancelled_at?->toIso8601ZuluString(),
                'cancellation_reason' => $event->cancellation_reason,
                // Masqué par la modération : plus dans l'agenda ; l'inscription est conservée.
                'hidden_by_moderation' => $event->isRestricted(),
            ],
            'announcements' => $this->when($this->withAnnouncements, fn () => AnnouncementResource::collection(
                $event->announcements
                    ->filter(fn (EventAnnouncement $announcement): bool => $announcement->status === AnnouncementStatus::Published
                        && $announcement->moderation_restricted_at === null)
                    ->sortBy([['published_at', 'desc'], ['id', 'desc']])
                    ->values(),
            )),
        ];
    }
}
