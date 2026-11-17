<?php

namespace App\Modules\Appointments\Actions;

use App\Models\User;
use App\Modules\Appointments\Enums\ExceptionKind;
use App\Modules\Appointments\Models\AvailabilityException;
use App\Modules\Appointments\Support\Calendars;
use App\Modules\Appointments\Support\Plan;
use App\Modules\Appointments\Support\TimeOfDay;
use App\Support\Time\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ajoute une exception aux plages hebdomadaires : fermeture (journée entière sans heures, ou
 * plage) ou ouverture ponctuelle (plage obligatoire), à une date locale entre aujourd'hui et
 * un an (fuseau du calendrier).
 *
 * Exceptions à venir : appointments.upcoming_exceptions (403 premium_required en gratuit, 409
 * sinon). Une fermeture n'annule aucun rendez-vous déjà pris.
 */
class CreateAvailabilityException
{
    public function __construct(private Plan $plan) {}

    /**
     * @param  array{date: string, kind: string, start?: string|null, end?: string|null}  $data
     */
    public function handle(User $provider, array $data): AvailabilityException
    {
        $calendar = Calendars::of($provider);
        $kind = ExceptionKind::from($data['kind']);
        $today = CarbonImmutable::parse(LocalTime::localDate(now(), $calendar->timezone));
        $date = CarbonImmutable::parse($data['date']);
        $start = isset($data['start']) ? TimeOfDay::toMinutes($data['start']) : null;
        $end = isset($data['end']) ? TimeOfDay::toMinutes($data['end']) : null;

        if ($date->lessThan($today) || $date->greaterThan($today->addDays((int) config('appointments.exceptions_max_days_ahead')))) {
            throw ValidationException::withMessages(['date' => 'Choisissez une date entre aujourd\'hui et dans un an.']);
        }

        if (($start === null) !== ($end === null) || ($kind === ExceptionKind::Open && $start === null)) {
            throw ValidationException::withMessages(['start' => $kind === ExceptionKind::Open
                ? 'Indiquez le début et la fin de l\'ouverture.'
                : 'Indiquez le début et la fin, ou aucun des deux pour fermer toute la journée.']);
        }

        if ($start !== null && $end !== null && $end <= $start) {
            throw ValidationException::withMessages(['end' => 'La fin doit être postérieure au début.']);
        }

        return DB::transaction(function () use ($provider, $calendar, $kind, $date, $today, $start, $end): AvailabilityException {
            Calendars::lock($calendar->id);

            $upcoming = AvailabilityException::query()
                ->where('calendar_id', $calendar->id)
                ->where('date', '>=', $today->toDateString())
                ->count();

            $this->plan->assertBelow($provider, 'upcoming_exceptions', $upcoming, 'availability_exceptions', 'Vous avez déjà %d exceptions à venir : supprimez-en une avant d\'en ajouter une autre.');

            $exception = new AvailabilityException;
            $exception->forceFill([
                'uuid' => (string) Str::uuid(),
                'calendar_id' => $calendar->id,
                'date' => $date->toDateString(),
                'kind' => $kind,
                'start_minute' => $start,
                'end_minute' => $end,
            ])->save();

            return $exception;
        });
    }
}
