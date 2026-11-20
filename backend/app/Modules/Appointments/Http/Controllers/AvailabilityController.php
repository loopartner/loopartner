<?php

namespace App\Modules\Appointments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Appointments\Actions\CreateAvailabilityException;
use App\Modules\Appointments\Actions\ReplaceAvailabilityRules;
use App\Modules\Appointments\Http\Requests\ReplaceAvailabilityRulesRequest;
use App\Modules\Appointments\Http\Requests\StoreAvailabilityExceptionRequest;
use App\Modules\Appointments\Models\AvailabilityException;
use App\Modules\Appointments\Models\AvailabilityRule;
use App\Modules\Appointments\Models\Calendar;
use App\Modules\Appointments\Support\Calendars;
use App\Modules\Appointments\Support\TimeOfDay;
use App\Support\Time\LocalTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Disponibilités du prestataire connecté : plages hebdomadaires (lecture, remplacement en
 * bloc) et exceptions (à venir, ajout, suppression). Heures locales du calendrier.
 */
class AvailabilityController extends Controller
{
    public function rules(Request $request): JsonResponse
    {
        Gate::authorize('manage', Calendar::class);
        $calendar = Calendars::of($this->user($request));

        return $this->rulesResponse($calendar, ReplaceAvailabilityRules::of($calendar)->all());
    }

    public function replaceRules(ReplaceAvailabilityRulesRequest $request, ReplaceAvailabilityRules $replaceRules): JsonResponse
    {
        /** @var list<array{weekday: int, start: string, end: string}> $rules */
        $rules = $request->validated('rules');
        $saved = $replaceRules->handle($this->user($request), $rules);

        return $this->rulesResponse(Calendars::of($this->user($request)), $saved->all());
    }

    public function exceptions(Request $request): JsonResponse
    {
        Gate::authorize('manage', Calendar::class);
        $calendar = Calendars::of($this->user($request));

        $exceptions = AvailabilityException::query()
            ->where('calendar_id', $calendar->id)
            ->where('date', '>=', LocalTime::localDate(now(), $calendar->timezone))
            ->orderBy('date')
            ->orderBy('start_minute')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $exceptions->map(fn (AvailabilityException $exception): array => self::exception($exception))->all(),
            'meta' => ['timezone' => $calendar->timezone],
        ]);
    }

    public function storeException(StoreAvailabilityExceptionRequest $request, CreateAvailabilityException $createException): JsonResponse
    {
        /** @var array{date: string, kind: string, start?: string|null, end?: string|null} $data */
        $data = $request->validated();

        return response()->json(['data' => self::exception($createException->handle($this->user($request), $data))], 201);
    }

    public function destroyException(Request $request, string $uuid): Response
    {
        Gate::authorize('manage', Calendar::class);
        $calendar = Calendars::of($this->user($request));
        $deleted = AvailabilityException::query()->where('calendar_id', $calendar->id)->where('uuid', $uuid)->delete();
        abort_if($deleted === 0, 404, 'Exception introuvable.');

        return response()->noContent();
    }

    /**
     * @param  list<AvailabilityRule>  $rules
     */
    private function rulesResponse(Calendar $calendar, array $rules): JsonResponse
    {
        return response()->json([
            'data' => array_map(fn (AvailabilityRule $rule): array => [
                'weekday' => $rule->weekday,
                'start' => TimeOfDay::format($rule->start_minute),
                'end' => TimeOfDay::format($rule->end_minute),
            ], $rules),
            'meta' => ['timezone' => $calendar->timezone],
        ]);
    }

    /**
     * @return array{uuid: string, date: string, kind: string, start: string|null, end: string|null}
     */
    private static function exception(AvailabilityException $exception): array
    {
        return [
            'uuid' => $exception->uuid,
            'date' => $exception->date->toDateString(),
            'kind' => $exception->kind->value,
            'start' => $exception->start_minute === null ? null : TimeOfDay::format($exception->start_minute),
            'end' => $exception->end_minute === null ? null : TimeOfDay::format($exception->end_minute),
        ];
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
