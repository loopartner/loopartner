<?php

namespace App\Modules\Appointments\Actions;

use App\Modules\Appointments\Enums\AppointmentNotice;
use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Enums\Party;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Notifications\AppointmentNotification;
use App\Modules\Appointments\Support\Plan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Tâche appointments:send-reminders (toutes les 5 minutes, par le planificateur : aucun
 * processus permanent). Rappel aux deux parties d'un rendez-vous confirmé, à chaque délai
 * choisi par le prestataire et permis par son plan (24 h par défaut ; Premium : jusqu'à deux
 * délais parmi 2 h, 24 h et 48 h).
 *
 * - Délais en **durée réelle** : « 24 h avant » un rendez-vous qui suit un changement d'heure
 *   part 24 heures avant, pas à la même heure locale la veille.
 * - Pas de rappel pour un délai déjà dépassé au moment de la confirmation (ou du dernier
 *   report) : la confirmation vient d'être envoyée.
 * - **Jamais deux fois** : chaque rappel (rendez-vous, partie, délai) est réservé avant l'envoi
 *   par une insertion dans appointment_reminders (index unique) ; une exécution concurrente ou
 *   relancée n'envoie rien de plus. Un report remet les rappels à zéro.
 * - Catégorie facultative appointments.reminders : un utilisateur peut les couper.
 */
class SendAppointmentReminders
{
    public function __construct(private Plan $plan) {}

    /**
     * @return int nombre de rappels envoyés
     */
    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $maxOffset = max([
            ...array_map('intval', (array) config('appointments.plans.premium.reminder_offsets')),
            ...array_map('intval', (array) config('appointments.plans.free.reminder_offsets')),
            ...array_map('intval', (array) config('appointments.default_reminder_offsets')),
        ]);
        $sent = 0;

        Appointment::query()
            ->where('status', AppointmentStatus::Confirmed->value)
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->addMinutes($maxOffset))
            ->with(['calendar', 'client', 'provider'])
            ->lazyById()
            ->each(function (Appointment $appointment) use ($now, &$sent): void {
                $offsets = $appointment->calendar !== null
                    ? $this->plan->reminderOffsets($appointment->calendar)
                    : array_map('intval', (array) config('appointments.default_reminder_offsets'));

                foreach ($offsets as $offset) {
                    $sent += $this->remind($appointment, $offset, $now);
                }
            });

        return $sent;
    }

    private function remind(Appointment $appointment, int $offset, CarbonImmutable $now): int
    {
        $dueAt = CarbonImmutable::parse($appointment->starts_at)->subMinutes($offset);
        $scheduledAt = $appointment->scheduled_at ?? $appointment->confirmed_at;

        if ($now->lessThan($dueAt) || ($scheduledAt !== null && $scheduledAt->greaterThan($dueAt))) {
            return 0;
        }

        $sent = 0;

        foreach ([Party::Client, Party::Provider] as $party) {
            $recipient = $party === Party::Client ? $appointment->client : $appointment->provider;

            if ($recipient === null) {
                continue;
            }

            $reserved = DB::table('appointment_reminders')->insertOrIgnore([
                'appointment_id' => $appointment->id,
                'recipient_side' => $party->value,
                'offset_minutes' => $offset,
                'sent_at' => $now,
            ]);

            if ($reserved === 1) {
                AppointmentNotification::sendTo($recipient, AppointmentNotice::Reminder, $appointment);
                $sent++;
            }
        }

        return $sent;
    }
}
