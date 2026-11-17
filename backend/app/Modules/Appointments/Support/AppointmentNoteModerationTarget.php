<?php

namespace App\Modules\Appointments\Support;

use App\Models\User;
use App\Modules\Appointments\Models\AppointmentNote;
use App\Support\Moderation\ModerationTarget;
use App\Support\Moderation\Restriction;
use App\Support\Moderation\TargetContent;
use Illuminate\Support\Facades\DB;

/**
 * Texte libre d'un rendez-vous signalable (module Moderation), désigné par son uuid : motif,
 * messages d'annulation, de refus ou de report. Contenu privé : signalé par l'autre partie du
 * rendez-vous (POST /me/appointments/{uuid}/notes/{noteUuid}/report), jamais par le formulaire
 * public. Masqué, il garde sa place sans son texte.
 */
class AppointmentNoteModerationTarget implements ModerationTarget
{
    public function publiclyReportable(): bool
    {
        return false;
    }

    public function findVisible(string $ref, ?User $viewer): ?TargetContent
    {
        if ($viewer === null) {
            return null;
        }

        $note = AppointmentNote::query()->with('appointment')->where('uuid', $ref)->whereNull('moderation_restricted_at')->whereNotNull('body')->first();
        $party = $note?->appointment->partyOf($viewer);

        return $note === null || $party === null || $party === $note->author_side ? null : self::content($note);
    }

    public function inspect(string $ref): ?TargetContent
    {
        $note = AppointmentNote::query()->where('uuid', $ref)->first();

        return $note === null ? null : self::content($note);
    }

    public function restrict(string $ref, string $decisionUuid): void
    {
        DB::transaction(function () use ($ref, $decisionUuid): void {
            $note = AppointmentNote::query()->where('uuid', $ref)->lockForUpdate()->first();

            if ($note !== null) {
                Restriction::apply($note, $decisionUuid);
            }
        });
    }

    public function lift(string $ref, string $decisionUuid): void
    {
        DB::transaction(function () use ($ref, $decisionUuid): void {
            $note = AppointmentNote::query()->where('uuid', $ref)->lockForUpdate()->first();

            if ($note !== null) {
                Restriction::lift($note, $decisionUuid);
            }
        });
    }

    private static function content(AppointmentNote $note): TargetContent
    {
        return new TargetContent(
            type: 'appointment_note',
            ref: $note->uuid,
            ownerId: $note->author_id,
            label: 'Message d\'un rendez-vous',
            text: (string) $note->body,
            version: TargetContent::fingerprint(['body' => $note->body]),
            restrictedBy: Restriction::decisionOf($note),
            publicPath: null,
        );
    }
}
