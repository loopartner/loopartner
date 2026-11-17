<?php

namespace App\Modules\Appointments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Appointments\Actions\SaveAppointmentType;
use App\Modules\Appointments\Http\Requests\SaveAppointmentTypeRequest;
use App\Modules\Appointments\Http\Resources\AppointmentTypeResource;
use App\Modules\Appointments\Models\AppointmentType;
use App\Modules\Appointments\Models\Calendar;
use App\Modules\Appointments\Support\BookableTypes;
use App\Modules\Appointments\Support\Calendars;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Types de rendez-vous du prestataire connecté. Le type d'un autre prestataire donne 404.
 */
class AppointmentTypeController extends Controller
{
    public function __construct(private BookableTypes $bookableTypes) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('manage', Calendar::class);
        $calendar = Calendars::of($this->user($request));
        $bookable = $this->bookableTypes->ids($calendar);

        return response()->json([
            'data' => $calendar->types()->get()->map(fn (AppointmentType $type): array => (new AppointmentTypeResource($type, $bookable))->resolve($request))->all(),
        ]);
    }

    public function store(SaveAppointmentTypeRequest $request, SaveAppointmentType $saveType): JsonResponse
    {
        $type = $saveType->create($this->user($request), $request->validated());

        return $this->resource($request, $type)->response()->setStatusCode(201);
    }

    public function show(Request $request, string $uuid): AppointmentTypeResource
    {
        Gate::authorize('manage', Calendar::class);

        return $this->resource($request, $this->find($request, $uuid));
    }

    public function update(SaveAppointmentTypeRequest $request, string $uuid, SaveAppointmentType $saveType): AppointmentTypeResource
    {
        $type = $saveType->update($this->user($request), $this->find($request, $uuid), $request->validated());

        return $this->resource($request, $type);
    }

    /**
     * Les rendez-vous déjà pris gardent leur copie du type (type_id passe à null).
     */
    public function destroy(Request $request, string $uuid): Response
    {
        Gate::authorize('manage', Calendar::class);
        $this->find($request, $uuid)->delete();

        return response()->noContent();
    }

    private function find(Request $request, string $uuid): AppointmentType
    {
        $type = AppointmentType::query()
            ->where('uuid', $uuid)
            ->whereHas('calendar', fn ($query) => $query->where('user_id', $this->user($request)->id))
            ->first();
        abort_if($type === null, 404, 'Type de rendez-vous introuvable.');

        return $type;
    }

    private function resource(Request $request, AppointmentType $type): AppointmentTypeResource
    {
        return new AppointmentTypeResource($type, $this->bookableTypes->ids(Calendars::of($this->user($request))));
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
