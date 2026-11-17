<?php

namespace App\Modules\Appointments\Support;

use App\Modules\Appointments\Enums\NoteKind;
use App\Modules\Appointments\Enums\Party;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Models\AppointmentNote;
use Illuminate\Support\Str;

/**
 * Ajoute un texte libre (chiffré) à un rendez-vous, s'il n'est pas vide.
 */
final class AppointmentNotes
{
    public static function add(Appointment $appointment, Party $author, NoteKind $kind, ?string $body): void
    {
        $body = $body === null ? '' : trim($body);

        if ($body === '') {
            return;
        }

        $note = new AppointmentNote;
        $note->forceFill([
            'uuid' => (string) Str::uuid(),
            'appointment_id' => $appointment->id,
            'author_id' => $appointment->userId($author),
            'author_side' => $author,
            'kind' => $kind,
            'body' => $body,
        ])->save();
    }
}
