<?php

namespace App\Modules\Events\Actions;

use App\Models\Media;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\RevisionStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAnnouncement;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventRevision;
use App\Support\Media\MediaLibrary;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Minimisation des données du module Events (tâche quotidienne events:prune). Durées en mois
 * dans config/security.php (retention) :
 *
 * - event_registrations_months : inscriptions aux événements terminés depuis cette durée
 *   (seul le compteur registrations_count reste sur l'événement) ;
 * - event_revisions_months : révisions décidées (validées, refusées, retirées) depuis cette durée ;
 * - event_announcements_months : annonces des événements terminés depuis cette durée ;
 * - closed_events_months : événements annulés, ou détachés de leur organisateur (compte
 *   supprimé), terminés depuis cette durée, avec leur couverture.
 */
class PruneEventData
{
    public function __construct(private MediaLibrary $library) {}

    /**
     * @return array{registrations: int, revisions: int, announcements: int, events: int}
     */
    public function handle(): array
    {
        $endedEvents = fn (string $setting) => Event::query()->select('id')->where('ends_at', '<=', self::cutoff($setting));

        return [
            'registrations' => EventRegistration::query()->whereIn('event_id', $endedEvents('event_registrations_months'))->delete(),
            'revisions' => $this->pruneRevisions(),
            'announcements' => EventAnnouncement::query()->whereIn('event_id', $endedEvents('event_announcements_months'))->delete(),
            'events' => $this->pruneClosedEvents(),
        ];
    }

    private function pruneRevisions(): int
    {
        $deleted = 0;

        EventRevision::query()
            ->where('status', '!=', RevisionStatus::Pending->value)
            ->where('updated_at', '<=', self::cutoff('event_revisions_months'))
            ->chunkById(200, function (Collection $revisions) use (&$deleted): void {
                DB::transaction(function () use ($revisions, &$deleted): void {
                    $this->deleteMedia($revisions->pluck('cover_media_id')->filter()->all());
                    $deleted += EventRevision::query()->whereKey($revisions->modelKeys())->delete();
                });
            });

        return $deleted;
    }

    private function pruneClosedEvents(): int
    {
        $deleted = 0;

        Event::query()
            ->where('ends_at', '<=', self::cutoff('closed_events_months'))
            ->where(fn ($query) => $query->whereNull('user_id')->orWhere('status', EventStatus::Cancelled->value))
            ->chunkById(200, function (Collection $events) use (&$deleted): void {
                DB::transaction(function () use ($events, &$deleted): void {
                    $covers = $events->pluck('cover_media_id')->filter()->all();
                    // Inscriptions, révisions, annonces et catégories supprimées par les clés étrangères.
                    $deleted += Event::query()->whereKey($events->modelKeys())->delete();
                    $this->deleteMedia($covers);
                });
            });

        return $deleted;
    }

    /**
     * @param  list<int>  $ids
     */
    private function deleteMedia(array $ids): void
    {
        Media::query()->whereKey($ids)->get()->each(fn (Media $media) => $this->library->delete($media));
    }

    private static function cutoff(string $setting): CarbonInterface
    {
        return now()->subMonths((int) config('security.retention.'.$setting));
    }
}
