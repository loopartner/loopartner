<?php

namespace App\Modules\Appointments\Http\Requests;

use App\Modules\Appointments\Models\Calendar;
use App\Modules\Appointments\Support\TypeRules;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /me/appointment-types (création) et PATCH /me/appointment-types/{uuid} (modification
 * partielle) : prestataire seulement (403), vérifié avant la validation.
 */
class SaveAppointmentTypeRequest extends FormRequest
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
        return TypeRules::rules($this->isMethod('PATCH'));
    }
}
