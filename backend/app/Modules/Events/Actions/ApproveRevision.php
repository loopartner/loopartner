<?php

namespace App\Modules\Events\Actions;

use App\Models\Media;
use App\Models\User;
use App\Modules\Events\Enums\EventNotice;
use App\Modules\Events\Enums\RevisionStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRevision;
use App\Modules\Events\Notifications\EventNotification;
use App\Modules\Events\Support\Attendees;
use App\Modules\Events\Support\EventContent;
use App\Modules\Events\Support\Moderation;
use App\Modules\Events\Support\Organizers;
use App\Support\AdminAudit;
use App\Support\Media\MediaLibrary;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Validation d'une révision par un admin : la version proposée remplace la version publiée
 * (contenu, catégories, couverture), la version de l'événement augmente, l'organisateur est
 * prévenu, et les inscrits aussi en cas de modification importante (date, lieu, lien).
 *
 * 409 si la révision n'est plus en attente, a changé depuis la lecture de l'admin (version),
 * si l'événement a commencé, ou si la version proposée n'est plus valide (capacité devenue
 * inférieure aux inscrits, début trop proche…).
 */
class ApproveRevision
{
    public function __construct(private MediaLibrary $library) {}

    /**
     * @throws ConflictHttpException
     */
    public function handle(User $admin, EventRevision $revision, int $version): EventRevision
    {
        return DB::transaction(function () use ($admin, $revision, $version): EventRevision {
            $event = Event::query()->whereKey($revision->event_id)->lockForUpdate()->firstOrFail();
            $revision = EventRevision::query()->whereKey($revision->id)->lockForUpdate()->firstOrFail();

            if (! $revision->isPending()) {
                throw new ConflictHttpException('Cette modification n\'est plus en attente de validation.');
            }

            Moderation::assertVersion($version, $revision->version);

            if (! $event->isUpcoming()) {
                throw new ConflictHttpException('Cet événement a commencé ou n\'est plus publié : la modification ne peut plus être appliquée.');
            }

            $organizer = Moderation::assertOrganizerEligible($event->user);
            $content = collect($revision->payload)->except('cover')->all();

            try {
                EventContent::assertComplete($content, Organizers::isProvider($organizer), $event->registrations_count);
            } catch (ValidationException $e) {
                throw new ConflictHttpException('La version proposée n\'est plus valide : '.collect($e->errors())->flatten()->first());
            }

            EventContent::apply($event, $content);
            $this->applyCover($event, $revision);
            $event->forceFill(['version' => $event->version + 1])->save();
            $event->categories()->sync(EventContent::categoryIds($content));

            $revision->forceFill([
                'status' => RevisionStatus::Approved,
                'pending_key' => null,
                'decided_at' => now(),
                'decided_by' => $admin->id,
            ])->save();

            AdminAudit::record($admin, 'events.revision.approved', 'revision', $revision->uuid, $revision->version);
            SecurityLog::record('events.revision_approved', ['user_id' => $admin->id, 'event' => $event->uuid, 'revision' => $revision->uuid]);

            EventNotification::sendTo($organizer, $event, EventNotice::RevisionApproved);

            if (array_intersect($revision->changed_fields, EventContent::IMPORTANT_FIELDS) !== []) {
                Attendees::notify($event, EventNotice::Changed);
            }

            return $revision;
        });
    }

    /**
     * Applique l'opération proposée sur la couverture : l'ancienne couverture est supprimée
     * (ligne et fichier) quand elle est remplacée ou retirée.
     */
    private function applyCover(Event $event, EventRevision $revision): void
    {
        $operation = $revision->coverOperation();

        if ($operation === EventRevision::COVER_KEEP) {
            return;
        }

        $previous = $event->cover_media_id !== null ? Media::query()->whereKey($event->cover_media_id)->first() : null;
        $replacement = $operation === EventRevision::COVER_REPLACE ? $revision->cover_media_id : null;

        $event->forceFill(['cover_media_id' => $replacement]);
        $revision->forceFill(['cover_media_id' => null]);
        $event->save();

        if ($previous !== null && $previous->id !== $replacement) {
            $this->library->delete($previous);
        }
    }
}
