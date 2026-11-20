<?php

namespace App\Modules\Appointments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Appointments\Actions\BookAppointment;
use App\Modules\Appointments\Http\Requests\AppointmentInputRequest;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Models\AppointmentType;
use App\Modules\Appointments\Support\AppointmentPresenter;
use App\Modules\Appointments\Support\BookableTypes;
use App\Modules\Appointments\Support\BookingNotices;
use App\Modules\Appointments\Support\Plan;
use App\Modules\Appointments\Support\Relation;
use App\Modules\Appointments\Support\SlotGenerator;
use App\Modules\Appointments\Support\SlotPresenter;
use App\Modules\Appointments\Support\SlotRange;
use App\Support\Locale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Rendez-vous dans une demande de mise en relation (décision 52), pour ses deux participants
 * (404 pour tout autre utilisateur) :
 *
 * - GET  /me/match-requests/{uuid}/booking : types réservables du prestataire et, avec
 *   ?type=, ses créneaux libres (journées locales from et to) ;
 * - GET  /me/match-requests/{uuid}/appointments : rendez-vous de cette relation ;
 * - POST /me/match-requests/{uuid}/appointments : le client réserve, le prestataire propose.
 */
class RelationAppointmentController extends Controller
{
    public function __construct(
        private Relation $relation,
        private AppointmentPresenter $presenter,
    ) {}

    public function booking(Request $request, string $uuid, BookableTypes $bookableTypes, SlotGenerator $slots, Plan $plan): JsonResponse
    {
        $request->validate([
            'type' => ['sometimes', 'string', 'uuid'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        ['context' => $context] = $this->relation->forBooking($this->user($request), $uuid);
        $calendar = $this->relation->openCalendarOf((int) $context['provider_id']);
        $types = $bookableTypes->of($calendar);
        $data = ['types' => $types->map(fn (AppointmentType $type): array => AppointmentPresenter::publicType($type))->values()->all(), 'slots' => []];
        $meta = ['timezone' => $calendar->timezone, 'notices' => BookingNotices::for(['note_warning'], Locale::fromRequest($request))];

        if ($request->filled('type')) {
            $type = $types->firstWhere('uuid', $request->string('type')->toString());

            if ($type === null) {
                throw ValidationException::withMessages(['type' => 'Choisissez un type de rendez-vous proposé par ce prestataire.']);
            }

            $range = SlotRange::fromRequest($request, $calendar);
            $data['slots'] = SlotPresenter::many($slots->between($calendar, $type->duration_minutes, $range['from'], $range['to'], $plan->horizonDays($calendar)), $calendar->timezone);
            $meta += ['from' => $range['from_date'], 'to' => $range['to_date']];
        }

        return response()->json(['data' => $data, 'meta' => $meta]);
    }

    public function index(Request $request, string $uuid): JsonResponse
    {
        $user = $this->user($request);
        ['context' => $context] = $this->relation->forReading($user, $uuid);

        $appointments = Appointment::query()
            ->where('match_request_id', $context['match_request_id'])
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json(['data' => $this->presenter->many($appointments, $user, Locale::fromRequest($request))]);
    }

    public function store(AppointmentInputRequest $request, string $uuid, BookAppointment $bookAppointment): JsonResponse
    {
        $user = $this->user($request);
        /** @var array{type: string, starts_at: string, note?: string|null, address?: string|null, phone?: string|null} $data */
        $data = $request->validated();
        $appointment = $bookAppointment->inRelation($user, $uuid, $data);

        return response()->json(['data' => $this->presenter->present($appointment, $user, Locale::fromRequest($request))], 201);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
