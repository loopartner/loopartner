<?php

namespace App\Modules\Appointments\Support;

use App\Modules\Appointments\Models\Calendar;
use App\Support\Time\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Journées locales demandées (from, to inclus, fuseau du calendrier) converties en instants
 * UTC : du début de la première journée au début du lendemain de la dernière.
 */
final class SlotRange
{
    /**
     * @return array{from: CarbonImmutable, to: CarbonImmutable, from_date: string, to_date: string}
     *
     * @throws ValidationException
     */
    public static function fromRequest(Request $request, Calendar $calendar): array
    {
        $maxDays = (int) config('appointments.max_slot_range_days');
        $today = CarbonImmutable::parse(LocalTime::localDate(now(), $calendar->timezone));
        $fromDate = $request->filled('from') ? CarbonImmutable::parse($request->string('from')->toString()) : $today;
        $toDate = $request->filled('to') ? CarbonImmutable::parse($request->string('to')->toString()) : $fromDate->addDays(6);

        if ($toDate->lessThan($fromDate) || $fromDate->diffInDays($toDate) > $maxDays - 1) {
            throw ValidationException::withMessages(['to' => "Demandez au plus {$maxDays} jours de créneaux à la fois."]);
        }

        return [
            'from' => LocalTime::instant($fromDate->toDateString(), 0, $calendar->timezone),
            'to' => LocalTime::instant($toDate->addDay()->toDateString(), 0, $calendar->timezone),
            'from_date' => $fromDate->toDateString(),
            'to_date' => $toDate->toDateString(),
        ];
    }
}
