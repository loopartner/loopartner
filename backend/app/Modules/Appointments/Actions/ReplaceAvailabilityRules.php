<?php

namespace App\Modules\Appointments\Actions;

use App\Models\User;
use App\Modules\Appointments\Models\AvailabilityRule;
use App\Modules\Appointments\Models\Calendar;
use App\Modules\Appointments\Support\Calendars;
use App\Modules\Appointments\Support\Plan;
use App\Modules\Appointments\Support\TimeOfDay;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Remplace en bloc les plages hebdomadaires du prestataire (heure locale du calendrier).
 *
 * - Fin après le début, plages d'un même jour sans chevauchement (422 sur la plage).
 * - Nombre de plages : appointments.weekly_ranges (403 premium_required en gratuit, 409 sinon).
 * - Les rendez-vous déjà pris ne changent pas.
 */
class ReplaceAvailabilityRules
{
    public function __construct(private Plan $plan) {}

    /**
     * @param  list<array{weekday: int, start: string, end: string}>  $rules
     * @return Collection<int, AvailabilityRule>
     */
    public function handle(User $provider, array $rules): Collection
    {
        $ranges = array_map(fn (array $rule): array => [
            'weekday' => (int) $rule['weekday'],
            'start_minute' => TimeOfDay::toMinutes($rule['start']),
            'end_minute' => TimeOfDay::toMinutes($rule['end']),
        ], $rules);

        $this->assertConsistent($ranges);
        $this->plan->assertAtMost($provider, 'weekly_ranges', count($ranges), 'weekly_ranges', 'Vous pouvez définir au plus %d plages hebdomadaires.');

        $calendar = Calendars::of($provider);

        return DB::transaction(function () use ($calendar, $ranges): Collection {
            Calendars::lock($calendar->id);
            AvailabilityRule::query()->where('calendar_id', $calendar->id)->delete();

            foreach ($ranges as $range) {
                (new AvailabilityRule)->forceFill(['calendar_id' => $calendar->id, ...$range])->save();
            }

            return self::of($calendar);
        });
    }

    /**
     * @return Collection<int, AvailabilityRule>
     */
    public static function of(Calendar $calendar): Collection
    {
        return AvailabilityRule::query()
            ->where('calendar_id', $calendar->id)
            ->orderBy('weekday')
            ->orderBy('start_minute')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<array{weekday: int, start_minute: int, end_minute: int}>  $ranges
     *
     * @throws ValidationException
     */
    private function assertConsistent(array $ranges): void
    {
        $errors = [];

        foreach ($ranges as $index => $range) {
            if ($range['end_minute'] <= $range['start_minute']) {
                $errors["rules.{$index}.end"] = 'La fin doit être postérieure au début.';

                continue;
            }

            foreach ($ranges as $otherIndex => $other) {
                if ($otherIndex < $index && $other['weekday'] === $range['weekday']
                    && $range['start_minute'] < $other['end_minute'] && $other['start_minute'] < $range['end_minute']) {
                    $errors["rules.{$index}.start"] = 'Cette plage chevauche une autre plage du même jour.';
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
