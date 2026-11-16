<?php

namespace App\Modules\Events\Support;

use App\Models\User;
use App\Modules\Events\Enums\AnnouncementStatus;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Models\EventAnnouncement;
use App\Support\Moderation\ModerationTarget;
use App\Support\Moderation\Restriction;
use App\Support\Moderation\TargetContent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Annonce d'un organisateur à ses inscrits, désignée par son uuid. Seuls les inscrits la voient,
 * donc seuls eux peuvent la signaler (404 pour tout autre visiteur). Masquée, elle disparaît de
 * la page de l'inscrit ; l'organisateur la voit avec son état de modération.
 */
class AnnouncementModerationTarget implements ModerationTarget
{
    public function publiclyReportable(): bool
    {
        return true;
    }

    public function findVisible(string $ref, ?User $viewer): ?TargetContent
    {
        if ($viewer === null) {
            return null;
        }

        $announcement = EventAnnouncement::query()
            ->where('uuid', $ref)
            ->where('status', AnnouncementStatus::Published->value)
            ->whereNull('moderation_restricted_at')
            ->whereHas('event.registrations', fn (Builder $registrations) => $registrations
                ->where('user_id', $viewer->id)
                ->where('status', RegistrationStatus::Registered->value))
            ->with('event')
            ->first();

        return $announcement === null ? null : self::content($announcement);
    }

    public function inspect(string $ref): ?TargetContent
    {
        $announcement = EventAnnouncement::query()->where('uuid', $ref)->with('event')->first();

        return $announcement === null ? null : self::content($announcement);
    }

    public function restrict(string $ref, string $decisionUuid): void
    {
        DB::transaction(function () use ($ref, $decisionUuid): void {
            $announcement = EventAnnouncement::query()->where('uuid', $ref)->lockForUpdate()->first();

            if ($announcement !== null) {
                Restriction::apply($announcement, $decisionUuid);
            }
        });
    }

    public function lift(string $ref, string $decisionUuid): void
    {
        DB::transaction(function () use ($ref, $decisionUuid): void {
            $announcement = EventAnnouncement::query()->where('uuid', $ref)->lockForUpdate()->first();

            if ($announcement !== null) {
                Restriction::lift($announcement, $decisionUuid);
            }
        });
    }

    private static function content(EventAnnouncement $announcement): TargetContent
    {
        return new TargetContent(
            type: 'event_announcement',
            ref: $announcement->uuid,
            ownerId: $announcement->event->user_id,
            label: $announcement->event->title,
            text: $announcement->body,
            version: TargetContent::fingerprint(['body' => $announcement->body]),
            restrictedBy: Restriction::decisionOf($announcement),
        );
    }
}
