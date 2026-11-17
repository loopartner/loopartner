<?php

namespace App\Modules\Appointments\Http\Requests;

use App\Models\User;
use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Enums\CancellationReason;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Support\BookingRules;
use App\Modules\Directory\Actions\GetMatchRequestContext;
use App\Support\Pagination;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Entrées des routes de rendez-vous, selon le nom de la route.
 *
 * L'accès est vérifié **avant la validation** : rendez-vous inconnu ou d'un autre utilisateur,
 * demande de mise en relation dont l'utilisateur n'est pas participant : 404, même avec un
 * corps invalide. La partie attendue (confirmation, refus…) est vérifiée par les Actions (403).
 */
class AppointmentInputRequest extends FormRequest
{
    /**
     * Routes qui visent un rendez-vous existant (paramètre uuid).
     *
     * @var list<string>
     */
    private const APPOINTMENT_ROUTES = [
        'appointments.confirm', 'appointments.decline', 'appointments.cancel', 'appointments.reschedule',
        'appointments.accept-change', 'appointments.decline-change',
    ];

    public function authorize(): Response
    {
        $name = (string) $this->route()?->getName();
        $uuid = (string) $this->route('uuid');

        if ($name === 'appointments.relation.store') {
            /** @var User $user */
            $user = $this->user();
            abort_if(app(GetMatchRequestContext::class)->handle($uuid, $user) === null, 404, 'Demande de mise en relation introuvable.');

            return Response::allow();
        }

        if (in_array($name, self::APPOINTMENT_ROUTES, true)) {
            $appointment = Appointment::query()->where('uuid', $uuid)->first();
            abort_if($appointment === null, 404, 'Rendez-vous introuvable.');

            return Gate::inspect('view', $appointment);
        }

        return Response::allow();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->route()?->getName()) {
            'appointments.relation.store' => BookingRules::booking(),
            'appointments.public.store' => BookingRules::booking(publicPage: true),
            'appointments.confirm' => BookingRules::clientLocation(),
            'appointments.decline' => ['message' => BookingRules::note()],
            'appointments.cancel' => [
                'reason' => ['required', 'string', Rule::enum(CancellationReason::class)],
                'message' => BookingRules::note(),
            ],
            'appointments.reschedule' => ['starts_at' => BookingRules::startsAt(), 'message' => BookingRules::note()],
            'appointments.index' => [
                'role' => ['sometimes', 'string', Rule::in(['client', 'provider'])],
                'scope' => ['sometimes', 'string', Rule::in(['upcoming', 'past', 'all'])],
                'status' => ['sometimes', 'string', Rule::enum(AppointmentStatus::class)],
                ...Pagination::rules(),
            ],
            default => [],
        };
    }
}
