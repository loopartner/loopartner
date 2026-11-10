<?php

namespace App\Modules\Accounts\Http\Requests;

use App\Models\User;
use App\Modules\Accounts\Enums\ProviderProfileStatus;
use App\Modules\Accounts\Models\ProviderProfile;
use App\Modules\Accounts\Support\PreferenceRules;
use App\Rules\ActiveServiceCategory;
use App\Rules\CountryCode;
use App\Rules\NoHtml;
use App\Support\Media\MediaPurpose;
use App\Support\Media\Rules\AttachableMedia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /me/provider-profile : représentation complète du profil.
 *
 * Un champ facultatif absent est remis à null. Le statut est facultatif : absent, il reste
 * inchangé (brouillon à la création). Le HTML est refusé dans tous les champs texte.
 *
 * logo suit la même exception que le statut : absent, il reste inchangé ; null le retire
 * (fichier compris) ; sinon c'est l'uuid d'un média "provider_logo" de l'utilisateur.
 *
 * categories suit la même exception : absent, rien ne change ; sinon c'est une liste de 1 à 5
 * slugs de catégories actives, qui remplace la précédente. Un profil publié doit avoir au
 * moins une catégorie (vérifié par UpsertProviderProfile).
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
        /** @var User $user */
        $user = $this->user();

        $currentLogoId = ProviderProfile::query()->where('user_id', $user->id)->value('logo_media_id');

        return [
            'display_name' => ['required', 'string', 'min:2', 'max:100', new NoHtml],
            'headline' => ['nullable', 'string', 'max:160', new NoHtml],
            'bio' => ['nullable', 'string', 'max:5000', new NoHtml],
            'city' => ['nullable', 'string', 'max:100', new NoHtml],
            'country' => ['nullable', 'string', 'size:2', new CountryCode],
            'website' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'status' => ['sometimes', 'required', 'string', Rule::enum(ProviderProfileStatus::class)],
            'categories' => ['sometimes', 'required', 'list', 'min:1', 'max:5'],
            'categories.*' => ['required', 'string', 'distinct', new ActiveServiceCategory],
            'logo' => [
                'sometimes',
                'nullable',
                'string',
                new AttachableMedia($user, MediaPurpose::ProviderLogo, $currentLogoId),
            ],
        ];
    }
}
