<?php

namespace App\Modules\Appointments\Http\Requests;

use App\Models\User;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Models\AppointmentNote;
use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * POST /me/appointments/{uuid}/notes/{noteUuid}/report : catégorie (liste fermée de
 * config/moderation.php) et commentaire facultatif. Rendez-vous ou note inconnus, ou
 * utilisateur qui ne participe pas : 404 avant la validation ; son propre texte : 422.
 */
class ReportAppointmentNoteRequest extends FormRequest
{
    private ?AppointmentNote $note = null;

    public function authorize(): Response
    {
        $appointment = Appointment::query()->where('uuid', (string) $this->route('uuid'))->first();
        abort_if($appointment === null, 404, 'Rendez-vous introuvable.');

        $response = Gate::inspect('view', $appointment);

        if ($response->denied()) {
            return $response;
        }

        $note = AppointmentNote::query()->where('appointment_id', $appointment->id)->where('uuid', (string) $this->route('noteUuid'))->first();
        abort_if($note === null, 404, 'Message introuvable.');

        /** @var User $user */
        $user = $this->user();

        if ($appointment->partyOf($user) === $note->author_side) {
            throw ValidationException::withMessages(['note' => 'Vous ne pouvez pas signaler votre propre message.']);
        }

        $this->note = $note;

        return Response::allow();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', Rule::in(array_keys((array) config('moderation.categories')))],
            'comment' => ['nullable', 'string', 'max:1000', new NoHtml, new NoInvisibleCharacters],
        ];
    }

    public function note(): AppointmentNote
    {
        abort_if($this->note === null, 404, 'Message introuvable.');

        return $this->note;
    }
}
