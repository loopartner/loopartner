<?php

namespace App\Modules\Appointments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Appointments\Actions\SaveCalendar;
use App\Modules\Appointments\Http\Requests\SlotRangeRequest;
use App\Modules\Appointments\Http\Requests\UpdateCalendarRequest;
use App\Modules\Appointments\Models\AppointmentType;
use App\Modules\Appointments\Models\Calendar;
use App\Modules\Appointments\Support\Calendars;
use App\Modules\Appointments\Support\Plan;
use App\Modules\Appointments\Support\SlotGenerator;
use App\Modules\Appointments\Support\SlotPresenter;
use App\Modules\Appointments\Support\SlotRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Calendrier du prestataire connecté : réglages (GET, PUT) et aperçu de ses créneaux libres
 * pour un de ses types (GET /me/appointments/slots), même réservations fermées.
 */
class CalendarController extends Controller
{
    public function __construct(private Plan $plan) {}

    public function show(Request $request): JsonResponse
    {
        Gate::authorize('manage', Calendar::class);
        $calendar = Calendar::query()->where('user_id', $this->user($request)->id)->first();

        return response()->json(['data' => $this->present($calendar ?? new Calendar(Calendars::defaults()), $calendar !== null, $this->user($request))]);
    }

    public function update(UpdateCalendarRequest $request, SaveCalendar $saveCalendar): JsonResponse
    {
        /** @var array{timezone: string, is_enabled: bool, public_page_enabled?: bool, slot_step_minutes: int, buffer_minutes: int, min_notice_hours: int, horizon_days: int, reminder_offsets: list<int>} $data */
        $data = $request->validated();
        $calendar = $saveCalendar->handle($this->user($request), $data);

        return response()->json(['data' => $this->present($calendar, true, $this->user($request))]);
    }

    public function slots(SlotRangeRequest $request, SlotGenerator $slots): JsonResponse
    {
        Gate::authorize('manage', Calendar::class);
        $calendar = Calendars::of($this->user($request));
        $type = AppointmentType::query()
            ->where('calendar_id', $calendar->id)
            ->where('uuid', $request->string('type')->toString())
            ->first();
        abort_if($type === null, 404, 'Type de rendez-vous introuvable.');

        $range = SlotRange::fromRequest($request, $calendar);

        return response()->json([
            'data' => SlotPresenter::many($slots->between($calendar, $type->duration_minutes, $range['from'], $range['to'], $this->plan->horizonDays($calendar)), $calendar->timezone),
            'meta' => ['timezone' => $calendar->timezone, 'from' => $range['from_date'], 'to' => $range['to_date']],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Calendar $calendar, bool $configured, User $provider): array
    {
        $set = $this->plan->of($provider);
        $feature = fn (string $name): mixed => $set->features['appointments.'.$name] ?? null;

        return [
            'configured' => $configured,
            'timezone' => $calendar->timezone,
            'is_enabled' => $calendar->is_enabled,
            'public_page_enabled' => $calendar->public_page_enabled,
            'slot_step_minutes' => $calendar->slot_step_minutes,
            'buffer_minutes' => $calendar->buffer_minutes,
            'min_notice_hours' => intdiv($calendar->min_notice_minutes, 60),
            'horizon_days' => $calendar->horizon_days,
            'reminder_offsets' => array_map('intval', $calendar->reminder_offsets ?? []),
            'effective' => [
                'public_page_open' => $configured && $this->plan->publicPageOpen($calendar),
                'horizon_days' => $configured ? $this->plan->horizonDays($calendar) : min($calendar->horizon_days, (int) $feature('horizon_days')),
                'reminder_offsets' => $configured ? $this->plan->reminderOffsets($calendar) : array_values(array_map('intval', (array) config('appointments.default_reminder_offsets'))),
            ],
            'limits' => [
                'plan' => $set->plan,
                'public_booking_page' => (bool) $feature('public_booking_page'),
                'active_types' => $feature('active_types'),
                'weekly_ranges' => $feature('weekly_ranges'),
                'upcoming_exceptions' => $feature('upcoming_exceptions'),
                'horizon_days' => $feature('horizon_days'),
                'upcoming_appointments' => $feature('upcoming_appointments'),
                'reminder_offsets' => $feature('reminder_offsets'),
                'max_reminder_offsets' => $feature('max_reminder_offsets'),
            ],
        ];
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
