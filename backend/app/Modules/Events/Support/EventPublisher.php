<?php

namespace App\Modules\Events\Support;

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use Illuminate\Support\Str;

/**
 * Publication d'un événement (validation par un admin, ou publication directe d'un admin).
 *
 * Le slug est calculé à la première publication, à partir du titre validé, puis ne change
 * plus : les URL publiques restent stables même si une révision modifie le titre. Le suffixe
 * aléatoire rend le slug imprévisible tant que l'événement n'est pas publié.
 */
final class EventPublisher
{
    private const SLUG_BASE_LENGTH = 80;

    public static function publish(Event $event): void
    {
        $event->forceFill([
            'status' => EventStatus::Published,
            'published_at' => now(),
            'slug' => $event->slug ?? self::uniqueSlug($event->title),
            'rejected_at' => null,
            'rejection_reason' => null,
        ])->save();
    }

    public static function uniqueSlug(string $title): string
    {
        $base = trim(Str::substr(Str::slug($title), 0, self::SLUG_BASE_LENGTH), '-');
        $base = $base === '' ? 'evenement' : $base;

        do {
            $slug = $base.'-'.Str::lower(Str::random(6));
        } while (Event::query()->where('slug', $slug)->exists());

        return $slug;
    }
}
