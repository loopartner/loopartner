<?php

namespace App\Modules\Events\Actions;

use App\Models\Media;
use App\Models\User;
use App\Modules\Events\Enums\RevisionStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRevision;
use App\Modules\Events\Support\EventContent;
use App\Modules\Events\Support\Organizers;
use App\Modules\Events\Support\PendingRevisions;
use App\Support\Media\MediaLibrary;
use App\Support\Media\MediaPurpose;
use App\Support\RateLimit\ActionThrottle;
use App\Support\SecurityLog;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Enregistre la modification d'un événement publié comme une révision en attente : le public
 * continue de voir la version validée, et la nouvelle version ne la remplace qu'après l'accord
 * d'un admin (ApproveRevision). Tous les champs visibles sont concernés, lien de connexion et
 * liens externes compris.
 *
 * - Au plus une révision en attente par événement : une nouvelle modification la remplace
 *   (version + 1, retour en fin de file). Les modifications successives s'accumulent.
 * - La version proposée doit être complète (EventContent::assertComplete).
 * - Si la version proposée ne diffère plus de la version publiée, la révision en attente est
 *   retirée et rien n'est créé (renvoie null).
 * - Couverture : "cover" absent garde la proposition précédente ; l'uuid de la couverture
 *   actuelle la conserve ; null propose de la retirer ; un autre uuid propose de la remplacer
 *   (le média est rattaché à la révision, puis à l'événement après validation).
 * - Limite de débit event_revisions (par organisateur, 429).
 *
 * À appeler dans une transaction, sur un événement verrouillé, publié et pas encore commencé.
 */
class SubmitRevision
{
    public function __construct(private MediaLibrary $library) {}

    /**
     * @param  array<string, mixed>  $data  données validées (champs présents uniquement)
     *
     * @throws ValidationException
     * @throws ThrottleRequestsException
     */
    public function handle(User $organizer, Event $event, array $data): ?EventRevision
    {
        $this->throttle($organizer);

        $pending = EventRevision::query()
            ->where('event_id', $event->id)
            ->where('status', RevisionStatus::Pending->value)
            ->lockForUpdate()
            ->first();

        $published = EventContent::of($event);
        $base = $pending !== null ? collect($pending->payload)->except('cover')->all() : $published;
        $content = EventContent::merge($base, $data);

        EventContent::assertComplete($content, Organizers::isProvider($organizer), $event->registrations_count);

        $revision = $pending ?? new EventRevision;
        $cover = $this->coverOperation($organizer, $event, $revision, $data);
        $changed = EventContent::changedFields($published, $content);

        if ($cover !== EventRevision::COVER_KEEP) {
            $changed[] = 'cover';
        }

        if ($changed === []) {
            if ($pending !== null) {
                PendingRevisions::supersede($pending, $this->library);
            }

            return null;
        }

        $revision->forceFill([
            'uuid' => $revision->uuid ?? (string) Str::uuid(),
            'event_id' => $event->id,
            'user_id' => $organizer->id,
            'status' => RevisionStatus::Pending,
            'version' => $pending !== null ? $pending->version + 1 : 1,
            'payload' => [...$content, 'cover' => $cover],
            'changed_fields' => $changed,
            'pending_key' => $event->id,
            'submitted_at' => now(),
        ])->save();

        SecurityLog::record('events.revision_submitted', [
            'user_id' => $organizer->id,
            'event' => $event->uuid,
            'revision' => $revision->uuid,
            'changed_fields' => $changed,
        ]);

        return $revision;
    }

    /**
     * Opération proposée sur la couverture, et rattachement du nouveau média à la révision.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function coverOperation(User $organizer, Event $event, EventRevision $revision, array $data): string
    {
        if (! array_key_exists('cover', $data)) {
            return $revision->exists ? $revision->coverOperation() : EventRevision::COVER_KEEP;
        }

        $uuid = is_string($data['cover']) ? $data['cover'] : null;
        $currentUuid = $event->cover_media_id !== null ? Media::query()->whereKey($event->cover_media_id)->value('uuid') : null;

        if ($uuid === null || $uuid === $currentUuid) {
            PendingRevisions::deleteProposedCover($revision, $this->library);

            return $uuid === null && $currentUuid !== null ? EventRevision::COVER_REMOVE : EventRevision::COVER_KEEP;
        }

        $revision->forceFill([
            'cover_media_id' => $this->library->replace($organizer, MediaPurpose::EventCover, $uuid, $revision->cover_media_id, 'cover'),
        ]);

        return EventRevision::COVER_REPLACE;
    }

    /**
     * @throws ThrottleRequestsException
     */
    private function throttle(User $organizer): void
    {
        ActionThrottle::hit('event-revisions', $organizer->id, [
            'hour' => [(int) config('security.rate_limits.event_revisions.per_hour'), 3600],
            'day' => [(int) config('security.rate_limits.event_revisions.per_day'), 86400],
        ]);
    }
}
