<?php

namespace App\Modules\Accounts\Http\Requests;

use App\Models\User;
use App\Modules\Accounts\Support\PreferenceRules;
use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;
use App\Support\Media\MediaPurpose;
use App\Support\Media\Rules\AttachableMedia;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /me : nom, préférences et avatar.
 *
 * avatar : uuid d'un média "avatar" envoyé par l'utilisateur (POST /media), ou null pour le retirer.
 *
 * L'email, les rôles et le mot de passe ne se modifient pas ici : les envoyer renvoie une
 * erreur 422 explicite plutôt que d'être ignorés en silence.
 */
class UpdateMeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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

        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:255', new NoHtml, new NoInvisibleCharacters],
            ...PreferenceRules::rules(),
            'avatar' => [
                'sometimes',
                'nullable',
                'string',
                new AttachableMedia($user, MediaPurpose::Avatar, $user->avatar_media_id),
            ],
            'email' => ['prohibited'],
            'password' => ['prohibited'],
            'roles' => ['prohibited'],
            'account_type' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.prohibited' => 'L\'adresse email ne peut pas être modifiée par cet endpoint.',
            'password.prohibited' => 'Le mot de passe se modifie via PUT /api/v1/auth/user/password.',
            'roles.prohibited' => 'Les rôles ne peuvent pas être modifiés par cet endpoint.',
            'account_type.prohibited' => 'Le type de compte ne peut pas être modifié par cet endpoint.',
        ];
    }
}
