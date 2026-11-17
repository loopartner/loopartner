<?php

namespace App\Modules\Appointments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Appointments\Actions\AnswerReschedule;
use App\Modules\Appointments\Actions\CancelAppointment;
use App\Modules\Appointments\Actions\ConfirmAppointment;
use App\Modules\Appointments\Actions\DeclineAppointment;
use App\Modules\Appointments\Actions\RescheduleAppointment;
use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Enums\CancellationReason;
use App\Modules\Appointments\Enums\Party;
use App\Modules\Appointments\Http\Requests\AppointmentInputRequest;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Support\AppointmentIcs;
use App\Modules\Appointments\Support\AppointmentPresenter;
use App\Support\Locale;
use App\Support\Pagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Rendez-vous de l'utilisateur connecté, comme client ou comme prestataire. Le rendez-vous d'un
 * autre utilisateur donne 404, admins compris (AppointmentPolicy), vérifié avant la validation.
 */
class AppointmentController extends Controller
{
    public function __construct(private AppointmentPresenter $presenter) {}

    /**
     * GET /me/appointments?role=client|provider&scope=upcoming|past|all&status=
     * À venir (par défaut) : du plus proche au plus lointain ; passés : du plus récent.
     */
    public function index(AppointmentInputRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $role = $request->string('role')->toString();
        $scope = $request->string('scope', 'upcoming')->toString();

        $appointments = Appointment::query()
            ->where(fn (Builder $query) => match ($role) {
                'client' => $query->where('client_id', $user->id),
                'provider' => $query->where('provider_id', $user->id),
                default => $query->where('client_id', $user->id)->orWhere('provider_id', $user->id),
            })
            ->when($scope === 'upcoming', fn (Builder $query) => $query->where('ends_at', '>', now())->orderBy('starts_at'))
            ->when($scope === 'past', fn (Builder $query) => $query->where('ends_at', '<=', now())->orderByDesc('starts_at'))
            ->when($scope === 'all', fn (Builder $query) => $query->orderByDesc('starts_at'))
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', AppointmentStatus::from($request->string('status')->toString())->value))
            ->orderBy('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return response()->json([
            'data' => $this->presenter->many($appointments->items(), $user, Locale::fromRequest($request)),
            'links' => ['next' => $appointments->nextPageUrl(), 'prev' => $appointments->previousPageUrl()],
            'meta' => ['current_page' => $appointments->currentPage(), 'last_page' => $appointments->lastPage(), 'per_page' => $appointments->perPage(), 'total' => $appointments->total()],
        ]);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        return $this->respond($request, $this->find($uuid));
    }

    public function confirm(AppointmentInputRequest $request, string $uuid, ConfirmAppointment $confirm): JsonResponse
    {
        $appointment = $this->find($uuid);
        /** @var array{address?: string|null, phone?: string|null} $data */
        $data = $request->validated();

        return $this->respond($request, $confirm->handle($this->user($request), $appointment, $data));
    }

    public function decline(AppointmentInputRequest $request, string $uuid, DeclineAppointment $decline): JsonResponse
    {
        $appointment = $this->find($uuid);

        return $this->respond($request, $decline->handle($this->user($request), $appointment, $request->validated('message')));
    }

    public function cancel(AppointmentInputRequest $request, string $uuid, CancelAppointment $cancel): JsonResponse
    {
        $appointment = $this->find($uuid);
        $reason = CancellationReason::from((string) $request->validated('reason'));

        return $this->respond($request, $cancel->handle($this->user($request), $appointment, $reason, $request->validated('message')));
    }

    public function reschedule(AppointmentInputRequest $request, string $uuid, RescheduleAppointment $reschedule): JsonResponse
    {
        $appointment = $this->find($uuid);

        return $this->respond($request, $reschedule->handle($this->user($request), $appointment, (string) $request->validated('starts_at'), $request->validated('message')));
    }

    public function acceptChange(Request $request, string $uuid, AnswerReschedule $answer): JsonResponse
    {
        return $this->respond($request, $answer->accept($this->user($request), $this->find($uuid)));
    }

    public function declineChange(Request $request, string $uuid, AnswerReschedule $answer): JsonResponse
    {
        return $this->respond($request, $answer->decline($this->user($request), $this->find($uuid)));
    }

    /**
     * GET /me/appointments/{uuid}/calendar.ics : fichier du rendez-vous pour une partie
     * connectée, avec le lieu ou le lien une fois confirmé (jamais dans les emails).
     */
    public function calendar(Request $request, string $uuid): Response
    {
        $appointment = $this->find($uuid);
        $party = $appointment->partyOf($this->user($request)) ?? Party::Client;

        return response(AppointmentIcs::forParty($appointment, $party, Locale::fromRequest($request)), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="rendez-vous.ics"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Rendez-vous visible de l'utilisateur connecté (404 sinon ; pour les routes avec un corps,
     * AppointmentInputRequest l'a déjà vérifié avant la validation).
     */
    private function find(string $uuid): Appointment
    {
        $appointment = Appointment::query()->where('uuid', $uuid)->first();
        abort_if($appointment === null, 404, 'Rendez-vous introuvable.');
        Gate::authorize('view', $appointment);

        return $appointment;
    }

    private function respond(Request $request, Appointment $appointment): JsonResponse
    {
        return response()->json(['data' => $this->presenter->present($appointment->refresh(), $this->user($request), Locale::fromRequest($request))]);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
