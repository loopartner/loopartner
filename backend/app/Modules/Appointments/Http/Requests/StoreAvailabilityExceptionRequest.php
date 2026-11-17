<?php

namespace App\Modules\Appointments\Http\Requests;

use App\Modules\Appointments\Enums\ExceptionKind;
use App\Modules\Appointments\Models\Calendar;
use App\Modules\Appointments\Support\TimeOfDay;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /me/appointments/availability/exceptions : date locale, fermeture ou ouverture, et
 * plage facultative (obligatoire pour une ouverture).
 */
class StoreAvailabilityExceptionRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('manage', Calendar::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'kind' => ['required', 'string', Rule::enum(ExceptionKind::class)],
            'start' => ['nullable', 'string', 'regex:'.TimeOfDay::PATTERN, 'not_in:24:00'],
            'end' => ['nullable', 'string', 'regex:'.TimeOfDay::PATTERN],
        ];
    }
}
