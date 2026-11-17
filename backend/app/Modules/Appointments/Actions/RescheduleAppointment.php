<?php

namespace App\Modules\Appointments\Actions;

use App\Models\User;
use App\Modules\Appointments\Enums\AppointmentNotice;
use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Enums\HoldKind;
use App\Modules\Appointments\Enums\NoteKind;
use App\Modules\Appointments\Enums\Party;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Models\Calendar;
use App\Modules\Appointments\Models\ReminderDelivery;
use App\Modules\Appointments\Notifications\AppointmentNotification;
use App\Modules\Appointments\Support\AppointmentLock;
use App\Modules\Appointments\Support\AppointmentNotes;
use App\Modules\Appointments\Support\BookableTypes;
use App\Modules\Appointments\Support\SlotLedger;
use App\Modules\Directory\Actions\IsBlockedBetween;
use App\Support\SecurityLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Report d'un rendez-vous confirmé (décision 55) : une partie propose un nouvel horaire,
 * retenu jusqu'à la réponse de l'autre (AnswerReschedule). Le client qui se reporte sur un
 * type en confirmation automatique est déplacé directement.
 *
 * - Rendez-vous non confirmé, commencé, ou report déjà en attente : 409 ; 3 reports au plus
 *   (appointments.max_reschedules) : 409 ; blocage : 403 ; même horaire : 422.
 * - Le nouveau créneau est vérifié comme une réservation (verrou du calendrier, même calcul,
 *   index unique), sans compter le créneau actuel du rendez-vous lui-même.
 * - L'horaire actuel reste valable tant que le report n'est pas accepté.
 */
class RescheduleAppointment
{
    public function __construct(
        private SlotLedger $ledger,
        private BookableTypes $bookableTypes,
        private IsBlockedBetween $isBlockedBetween,
    ) {}

    public function handle(User $actor, Appointment $appointment, string $startsAt, ?string $message = null): Appointment
    {
        $moved = false;

        $appointment = DB::transaction(function () use ($actor, $appointment, $startsAt, $message, &$moved): Appointment {
            ['appointment' => $appointment, 'calendar' => $calendar] = AppointmentLock::lock($appointment);
            $party = $appointment->partyOf($actor);

            if ($party === null) {
                throw new AccessDeniedHttpException('Vous ne participez pas à ce rendez-vous.');
            }

            $this->assertReschedulable($appointment, $calendar, $party);
            /** @var Calendar $calendar */
            $newStart = CarbonImmutable::parse($startsAt)->utc()->startOfMinute();
            $newEnd = $newStart->addMinutes($appointment->duration_minutes);

            if ($newStart->equalTo($appointment->starts_at)) {
                throw ValidationException::withMessages(['starts_at' => 'Choisissez un autre horaire que l\'horaire actuel.']);
            }

            $this->ledger->assertFree($calendar, $appointment->duration_minutes, $newStart, $appointment->id);
            AppointmentNotes::add($appointment, $party, NoteKind::Reschedule, $message);

            if ($party === Party::Client && $this->autoConfirms($appointment, $calendar)) {
                SlotLedger::release($appointment, HoldKind::Current);
                $this->ledger->hold($calendar, $appointment, HoldKind::Current, $newStart, $newEnd);
                self::moveTo($appointment, $newStart, $newEnd);
                $moved = true;

                AppointmentNotification::sendTo($appointment->client, AppointmentNotice::ChangeAccepted, $appointment);
                AppointmentNotification::sendTo($appointment->provider, AppointmentNotice::ChangeAccepted, $appointment);

                return $appointment;
            }

            $this->ledger->hold($calendar, $appointment, HoldKind::Proposed, $newStart, $newEnd);
            $appointment->forceFill([
                'change_starts_at' => $newStart,
                'change_ends_at' => $newEnd,
                'change_by' => $party,
                'change_expires_at' => CarbonImmutable::now()
                    ->addHours((int) config('appointments.request_expiration_hours'))
                    ->min($newStart->subMinutes($calendar->min_notice_minutes))
                    ->min(CarbonImmutable::parse($appointment->starts_at)),
                'reschedule_count' => $appointment->reschedule_count + 1,
            ])->save();

            AppointmentNotification::sendTo($party === Party::Client ? $appointment->provider : $appointment->client, AppointmentNotice::ChangeProposed, $appointment);

            return $appointment;
        });

        SecurityLog::record($moved ? 'appointments.rescheduled' : 'appointments.reschedule_proposed', ['user_id' => $actor->id, 'appointment' => $appointment->uuid]);

        return $appointment;
    }

    /**
     * Le rendez-vous prend son nouvel horaire : version + 1, rappels repartis à zéro.
     */
    public static function moveTo(Appointment $appointment, CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
        $appointment->forceFill([
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'version' => $appointment->version + 1,
            'scheduled_at' => now(),
            'change_starts_at' => null,
            'change_ends_at' => null,
            'change_by' => null,
            'change_expires_at' => null,
            'reschedule_count' => $appointment->reschedule_count + ($appointment->change_starts_at === null ? 1 : 0),
        ])->save();

        ReminderDelivery::query()->where('appointment_id', $appointment->id)->delete();
    }

    private function assertReschedulable(Appointment $appointment, ?Calendar $calendar, Party $party): void
    {
        if ($appointment->status !== AppointmentStatus::Confirmed) {
            throw new ConflictHttpException('Seul un rendez-vous confirmé peut être reporté.');
        }

        if ($appointment->hasStarted()) {
            throw new ConflictHttpException('Un rendez-vous commencé ne se reporte plus.');
        }

        if ($appointment->hasPendingChange()) {
            throw new ConflictHttpException('Un report est déjà en attente de réponse.');
        }

        if ($appointment->reschedule_count >= (int) config('appointments.max_reschedules')) {
            throw new ConflictHttpException(sprintf('Ce rendez-vous a déjà été reporté %d fois : annulez-le et prenez-en un nouveau.', (int) config('appointments.max_reschedules')));
        }

        if ($calendar === null || $appointment->provider_id === null || $appointment->client_id === null) {
            throw new ConflictHttpException('L\'autre participant n\'est plus inscrit.');
        }

        if ($party === Party::Client && ! $calendar->is_enabled) {
            throw new ConflictHttpException('Ce prestataire ne prend pas de rendez-vous en ligne pour le moment.');
        }

        if ($this->isBlockedBetween->handle($appointment->provider_id, $appointment->client_id)) {
            throw new AccessDeniedHttpException('Vous ne pouvez pas reporter ce rendez-vous.');
        }
    }

    private function autoConfirms(Appointment $appointment, Calendar $calendar): bool
    {
        $type = $appointment->type;

        return $type !== null && $type->auto_confirm && in_array($type->id, $this->bookableTypes->ids($calendar), true);
    }
}
