<?php

namespace App\Modules\Accounts\Http\Requests;

use App\Modules\Accounts\Enums\ProviderProfileStatus;
use App\Modules\Accounts\Models\ProviderProfile;
use App\Modules\Accounts\Rules\CountryCode;
use App\Modules\Accounts\Rules\NoHtml;
use App\Modules\Accounts\Support\PreferenceRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /me/provider-profile : représentation complète du profil.
 *
 * Un champ facultatif absent est remis à null. Le statut est facultatif : absent, il reste
 * inchangé (brouillon à la création). Le HTML est refusé dans tous les champs texte.
 */
class UpsertProviderProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('upsert', ProviderProfile::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->replace(PreferenceRules::normalize($this->all()));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'min:2', 'max:100', new NoHtml],
            'headline' => ['nullable', 'string', 'max:160', new NoHtml],
            'bio' => ['nullable', 'string', 'max:5000', new NoHtml],
            'city' => ['nullable', 'string', 'max:100', new NoHtml],
            'country' => ['nullable', 'string', 'size:2', new CountryCode],
            'website' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'status' => ['sometimes', 'required', 'string', Rule::enum(ProviderProfileStatus::class)],
        ];
    }
}
