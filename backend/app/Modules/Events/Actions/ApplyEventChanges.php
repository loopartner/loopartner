<?php

namespace App\Modules\Events\Actions;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\EventContent;
use App\Modules\Events\Support\Organizers;
use App\Support\Media\MediaLibrary;
use App\Support\Media\MediaPurpose;
use Illuminate\Validation\ValidationException;

/**
 * Applique directement une modification à un événement : brouillon ou événement refusé de
 * l'organisateur, ou événement publié d'un admin (sans validation). À appeler dans une
 * transaction, sur un événement verrouillé.
 *
 * La version augmente si le contenu ou la couverture change.
 */
class ApplyEventChanges
{
    public function __construct(private MediaLibrary $library) {}

    /**
     * @param  array<string, mixed>  $data  données validées (champs présents uniquement)
     * @return list<string> champs modifiés ("cover" compris)
     *
     * @throws ValidationException
     */
    public function handle(User $organizer, Event $event, array $data, bool $requireComplete): array
    {
        $before = EventContent::of($event);
        $content = EventContent::merge($before, $data);

        if ($requireComplete) {
            EventContent::assertComplete($content, Organizers::isProvider($organizer), $event->registrations_count);
        } else {
            EventContent::assertConsistent($content, $event->registrations_count);
        }

        $changed = EventContent::changedFields($before, $content);
        EventContent::apply($event, $content);

        if (array_key_exists('cover', $data)) {
            $coverId = $this->library->replace(
                $organizer,
                MediaPurpose::EventCover,
                is_string($data['cover']) ? $data['cover'] : null,
                $event->cover_media_id,
                'cover',
            );

            if ($coverId !== $event->cover_media_id) {
                $changed[] = 'cover';
                $event->forceFill(['cover_media_id' => $coverId]);
            }
        }

        if ($changed !== []) {
            $event->forceFill(['version' => $event->version + 1]);
        }

        $event->save();
        $event->categories()->sync(EventContent::categoryIds($content));

        return $changed;
    }
}
