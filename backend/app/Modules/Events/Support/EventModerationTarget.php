<?php

namespace App\Modules\Events\Support;

use App\Models\User;
use App\Modules\Events\Enums\EventNotice;
use App\Modules\Events\Models\Event;
use App\Support\Moderation\ModerationTarget;
use App\Support\Moderation\Restriction;
use App\Support\Moderation\TargetContent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Événement de l'agenda signalable (module Moderation), désigné par son slug public (la
 * modération accepte aussi l'uuid). Masqué : absent de l'agenda, fiche et .ics en 404,
 * inscriptions, rappels et annonces fermés ; les inscrits d'un événement à venir sont
 * prévenus par un email neutre et gardent leur inscription (décision 24). La modération avant
 * publication et l'annulation restent celles du module Events, inchangées.
 */
class EventModerationTarget implements ModerationTarget
{
    public function publiclyReportable(): bool
    {
        return true;
    }

    public function findVisible(string $ref, ?User $viewer): ?TargetContent
    {
        $event = Event::query()->publiclyVisible()->notRestricted()->with('cover')->where('slug', $ref)->first();

        return $event === null ? null : self::content($event);
    }

    public function inspect(string $ref): ?TargetContent
    {
        $event = self::query($ref)->with('cover')->first();

        return $event === null ? null : self::content($event);
    }

    public function restrict(string $ref, string $decisionUuid): void
    {
        $event = DB::transaction(function () use ($ref, $decisionUuid): ?Event {
            $event = self::query($ref)->lockForUpdate()->first();

            if ($event === null || $event->isRestricted()) {
                return null;
            }

            Restriction::apply($event, $decisionUuid);

            return $event;
        });

        if ($event !== null && $event->isUpcoming()) {
            Attendees::notify($event, EventNotice::HiddenByModeration);
        }
    }

    public function lift(string $ref, string $decisionUuid): void
    {
        DB::transaction(function () use ($ref, $decisionUuid): void {
            $event = self::query($ref)->lockForUpdate()->first();

            if ($event !== null) {
                Restriction::lift($event, $decisionUuid);
            }
        });
    }

    /**
     * @return Builder<Event>
     */
    private static function query(string $ref): Builder
    {
        return Event::query()->where(fn (Builder $query) => $query->where('slug', $ref)->orWhere('uuid', $ref));
    }

    private static function content(Event $event): TargetContent
    {
        $cover = $event->cover?->url();

        return new TargetContent(
            type: 'event',
            ref: $event->slug ?? $event->uuid,
            ownerId: $event->user_id,
            label: $event->title,
            text: TargetContent::joinText([
                'Titre' => $event->title,
                'Description' => $event->description,
                'Organisateur' => $event->organizer_name,
                'Lieu' => $event->venue_name,
                'Ville' => $event->city,
                'Pays' => $event->country,
                'Site' => $event->external_url,
                'Billetterie' => $event->ticket_url,
            ]),
            version: TargetContent::fingerprint(['version' => $event->version]),
            restrictedBy: Restriction::decisionOf($event),
            images: $cover !== null ? [$cover] : [],
            publicPath: $event->slug !== null ? '/events/'.$event->slug : null,
        );
    }
}
