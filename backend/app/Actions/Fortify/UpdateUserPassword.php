<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordValidationRules;

    /**
     * Valide et met à jour le mot de passe de l'utilisateur connecté.
     * Le mot de passe actuel est exigé ; le nouveau suit les mêmes règles qu'à l'inscription.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        /** @var array{password: string} $validated */
        $validated = Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => $this->passwordRules(),
        ], [
            'current_password.current_password' => 'Le mot de passe actuel est incorrect.',
        ])->validate();

        $user->forceFill([
            'password' => Hash::make($validated['password']),
        ])->save();
    }
}
