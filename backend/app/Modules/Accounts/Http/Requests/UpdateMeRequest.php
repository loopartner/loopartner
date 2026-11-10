<?php

namespace App\Modules\Accounts\Http\Requests;

use App\Modules\Accounts\Support\PreferenceRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /me : nom et préférences uniquement.
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
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            ...PreferenceRules::rules(),
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
