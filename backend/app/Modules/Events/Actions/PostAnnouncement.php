<?php

namespace App\Modules\Events\Actions;

use App\Models\User;
use App\Modules\Events\Enums\AnnouncementStatus;
use App\Modules\Events\Enums\EventNotice;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAnnouncement;
use App\Modules\Events\Support\Attendees;
use App\Support\Links\ExternalLink;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Annonce de l'organisateur à ses inscrits, sans jamais lui donner leurs coordonnées.
 *
 * - Sans lien ni nom de domaine : publiée tout de suite, les inscrits sont prévenus par un email
 *   qui ne contient pas le texte (ils le lisent sur la page de l'événement, connectés).
 * - Avec un lien ou un nom de domaine : en attente de la validation d'un admin (pending_review).
 * - Limites (config/events.php) : une annonce par période (cooldown_hours) et "per_event" au
 *   total par événement (annonces refusées non comptées), en plus de la limite de débit.
 */
class PostAnnouncement
{
    /**
     * @throws ConflictHttpException
     */
    public function handle(User $organizer, Event $event, string $body): EventAnnouncement
    {
        return DB::transaction(function () use ($organizer, $event, $body): EventAnnouncement {
            $event = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            if (! $event->isLive()) {
                throw new ConflictHttpException('Seul un événement publié et pas encore terminé peut faire l\'objet d\'une annonce.');
            }

            if ($event->isRestricted()) {
                throw new ConflictHttpException('Cet événement est masqué par la modération : aucune annonce ne peut être envoyée.');
            }

            $previous = EventAnnouncement::query()
                ->where('event_id', $event->id)
                ->where('status', '!=', AnnouncementStatus::Rejected->value);

            $perEvent = (int) config('events.announcements.per_event');
            $cooldown = (int) config('events.announcements.cooldown_hours');

            if ((clone $previous)->count() >= $perEvent) {
                throw new ConflictHttpException("Vous avez déjà envoyé {$perEvent} annonces pour cet événement : c'est le maximum.");
            }

            if ((clone $previous)->where('created_at', '>', now()->subHours($cooldown))->exists()) {
                throw new ConflictHttpException("Une seule annonce par période de {$cooldown} heures pour un même événement.");
            }

            $announcement = new EventAnnouncement(['body' => $body]);
            $hasLinks = ExternalLink::extract($body) !== [];

            $announcement->forceFill([
                'uuid' => (string) Str::uuid(),
                'event_id' => $event->id,
                'user_id' => $organizer->id,
                'has_links' => $hasLinks,
                'status' => $hasLinks ? AnnouncementStatus::PendingReview : AnnouncementStatus::Published,
            ])->save();

            if ($hasLinks) {
                SecurityLog::record('events.announcement_submitted', ['user_id' => $organizer->id, 'event' => $event->uuid, 'announcement' => $announcement->uuid]);

                return $announcement;
            }

            self::publish($event, $announcement);

            return $announcement;
        });
    }

    /**
     * Publie l'annonce et prévient les inscrits actifs.
     */
    public static function publish(Event $event, EventAnnouncement $announcement): void
    {
        $announcement->forceFill([
            'status' => AnnouncementStatus::Published,
            'published_at' => now(),
            'recipients_count' => Attendees::notify($event, EventNotice::AnnouncementPublished),
        ])->save();

        SecurityLog::record('events.announcement_published', [
            'event' => $event->uuid,
            'announcement' => $announcement->uuid,
            'recipients' => $announcement->recipients_count,
        ]);
    }
}
