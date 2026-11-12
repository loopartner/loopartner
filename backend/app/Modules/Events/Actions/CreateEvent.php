<?php

namespace App\Modules\Events\Actions;

use App\Models\User;
use App\Modules\Events\Enums\AddressVisibility;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\EventContent;
use App\Support\Media\MediaLibrary;
use App\Support\Media\MediaPurpose;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Crée un événement en brouillon pour son organisateur. Rien n'est visible du public avant la
 * validation d'un admin (ou la publication directe d'un admin, SubmitEvent).
 */
class CreateEvent
{
    public function __construct(private MediaLibrary $library) {}

    /**
     * @param  array<string, mixed>  $data  données validées
     *
     * @throws ValidationException
     */
    public function handle(User $organizer, array $data): Event
    {
        $content = EventContent::merge(self::emptyContent(), $data);
        EventContent::assertConsistent($content);

        return DB::transaction(function () use ($organizer, $data, $content): Event {
            $event = new Event;
            EventContent::apply($event, $content);

            $event->forceFill([
                'uuid' => (string) Str::uuid(),
                'user_id' => $organizer->id,
                'status' => EventStatus::Draft,
                'version' => 1,
            ]);

            if (isset($data['cover']) && is_string($data['cover'])) {
                $event->forceFill([
                    'cover_media_id' => $this->library->replace($organizer, MediaPurpose::EventCover, $data['cover'], null, 'cover'),
                ]);
            }

            $event->save();
            $event->categories()->sync(EventContent::categoryIds($content));

            return $event;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyContent(): array
    {
        return [
            ...array_fill_keys(EventContent::FIELDS, null),
            'is_free' => true,
            'address_visibility' => AddressVisibility::Public->value,
            'categories' => [],
        ];
    }
}
