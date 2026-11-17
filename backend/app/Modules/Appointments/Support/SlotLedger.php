<?php

namespace App\Modules\Appointments\Support;

use App\Modules\Appointments\Enums\HoldKind;
use App\Modules\Appointments\Exceptions\SlotUnavailable;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Models\Calendar;
use App\Modules\Appointments\Models\SlotHold;
use App\Support\SecurityLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Créneaux retenus (table appointment_slot_holds) : **aucun créneau réservé deux fois**.
 *
 * 1. L'appelant verrouille la ligne du calendrier du prestataire (Calendars::lock) : toutes
 *    les opérations qui retiennent un créneau de ce prestataire passent l'une après l'autre.
 * 2. assertFree() recalcule les créneaux libres (même calcul que l'affichage) et refuse un
 *    créneau qui chevauche un créneau retenu, marge comprise (409 slot_unavailable).
 * 3. hold() insère la ligne ; son slot_key (calendrier et début UTC) est unique : si deux
 *    opérations passaient malgré tout le contrôle, la base refuserait la seconde (409).
 *
 * Un créneau est libéré (lignes supprimées) dès que le rendez-vous est refusé, annulé ou
 * expiré, ou qu'un report est accepté (ancien créneau) ou refusé (créneau proposé).
 */
final class SlotLedger
{
    public function __construct(
        private SlotGenerator $slots,
        private Plan $plan,
    ) {}

    /**
     * @throws SlotUnavailable
     */
    public function assertFree(Calendar $calendar, int $durationMinutes, CarbonImmutable $startsAt, ?int $ignoreAppointmentId = null): void
    {
        if (! $this->slots->isFree($calendar, $durationMinutes, $startsAt, $this->plan->horizonDays($calendar), $ignoreAppointmentId)) {
            throw new SlotUnavailable;
        }
    }

    /**
     * @throws SlotUnavailable
     */
    public function hold(Calendar $calendar, Appointment $appointment, HoldKind $kind, CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
        $hold = new SlotHold;
        $hold->forceFill([
            'appointment_id' => $appointment->id,
            'calendar_id' => $calendar->id,
            'kind' => $kind,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'slot_key' => SlotHold::keyFor($calendar->id, $startsAt),
        ]);

        try {
            $hold->save();
        } catch (UniqueConstraintViolationException) {
            SecurityLog::record('appointments.slot_conflict', ['appointment' => $appointment->uuid], 'warning');

            throw new SlotUnavailable;
        }
    }

    /**
     * Libère les créneaux retenus par un rendez-vous (tous, ou ceux d'un genre).
     */
    public static function release(Appointment $appointment, ?HoldKind $kind = null): void
    {
        SlotHold::query()
            ->where('appointment_id', $appointment->id)
            ->when($kind !== null, fn ($query) => $query->where('kind', $kind?->value))
            ->delete();
    }

    /**
     * Report accepté : l'ancien créneau est libéré et le créneau proposé devient le créneau du
     * rendez-vous (même ligne, même slot_key).
     */
    public static function promoteProposed(Appointment $appointment): void
    {
        self::release($appointment, HoldKind::Current);

        SlotHold::query()
            ->where('appointment_id', $appointment->id)
            ->where('kind', HoldKind::Proposed->value)
            ->update(['kind' => HoldKind::Current->value]);
    }
}
