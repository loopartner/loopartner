<?php

namespace App\Modules\Appointments\Actions;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Models\AppointmentNote;
use App\Modules\Appointments\Models\AvailabilityException;
use App\Modules\Appointments\Models\ReminderDelivery;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Minimisation des rendez-vous (tâche quotidienne appointments:prune, décision 57). Durées en
 * mois dans config/security.php (retention) :
 *
 * - lieu (adresse, téléphone, lien de visio) et textes libres : effacés appointment_details_months
 *   après la fin du rendez-vous ou sa clôture (refus, annulation, expiration) ; le rendez-vous
 *   reste ;
 * - rendez-vous refusés, annulés ou expirés : supprimés closed_appointments_months après ;
 * - rendez-vous passés : supprimés past_appointments_months après leur fin (ils disparaissent
 *   aussi avec leur conversation : clé étrangère en cascade) ;
 * - traces des rappels et exceptions de disponibilité passées : supprimées après
 *   appointment_reminders_months et past_availability_exceptions_months.
 *
 * @return array{details: int, closed: int, past: int, reminders: int, exceptions: int}
 */
class PruneAppointmentData
{
    /**
     * @return array{details: int, closed: int, past: int, reminders: int, exceptions: int}
     */
    public function handle(): array
    {
        $detailsCutoff = self::cutoff('appointment_details_months');
        $now = now();

        $notes = AppointmentNote::query()
            ->whereNull('erased_at')
            ->whereIn('appointment_id', self::endedBefore($detailsCutoff)->select('id'))
            ->update(['body' => null, 'erased_at' => $now, 'updated_at' => $now]);

        $locations = self::endedBefore($detailsCutoff)
            ->whereNotNull('location')
            ->update(['location' => null, 'location_erased_at' => $now, 'updated_at' => $now]);

        return [
            'details' => $notes + $locations,
            'closed' => self::closedBefore(self::cutoff('closed_appointments_months'))->delete(),
            'past' => Appointment::query()
                ->where('status', AppointmentStatus::Confirmed->value)
                ->where('ends_at', '<=', self::cutoff('past_appointments_months'))
                ->delete(),
            'reminders' => ReminderDelivery::query()->where('sent_at', '<=', self::cutoff('appointment_reminders_months'))->delete(),
            'exceptions' => AvailabilityException::query()->where('date', '<', self::cutoff('past_availability_exceptions_months')->toDateString())->delete(),
        ];
    }

    /**
     * Rendez-vous terminés (confirmés et finis) ou clos avant cette date.
     *
     * @return Builder<Appointment>
     */
    private static function endedBefore(CarbonInterface $cutoff): Builder
    {
        return Appointment::query()->where(fn (Builder $query) => $query
            ->where(fn (Builder $confirmed) => $confirmed->where('status', AppointmentStatus::Confirmed->value)->where('ends_at', '<=', $cutoff))
            ->orWhere(fn (Builder $closed) => self::closedOnOrBefore($closed, $cutoff)));
    }

    /**
     * Rendez-vous refusés, annulés ou expirés avant cette date.
     *
     * @return Builder<Appointment>
     */
    private static function closedBefore(CarbonInterface $cutoff): Builder
    {
        return Appointment::query()->where(fn (Builder $query) => self::closedOnOrBefore($query, $cutoff));
    }

    /**
     * Date de clôture lue dans sa colonne dédiée (jamais updated_at, que l'effacement des
     * détails modifie).
     *
     * @param  Builder<Appointment>  $query
     */
    private static function closedOnOrBefore(Builder $query, CarbonInterface $cutoff): void
    {
        $query->where(fn (Builder $cancelled) => $cancelled->where('status', AppointmentStatus::Cancelled->value)->where('cancelled_at', '<=', $cutoff))
            ->orWhere(fn (Builder $declined) => $declined->where('status', AppointmentStatus::Declined->value)->where('declined_at', '<=', $cutoff))
            ->orWhere(fn (Builder $expired) => $expired->where('status', AppointmentStatus::Expired->value)->where('expired_at', '<=', $cutoff));
    }

    private static function cutoff(string $setting): CarbonInterface
    {
        return now()->subMonths((int) config('security.retention.'.$setting));
    }
}
