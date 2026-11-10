<?php

namespace App\Modules\Accounts\Actions;

use App\Models\User;
use App\Modules\Accounts\Enums\AccountType;
use App\Modules\Accounts\Notifications\RegistrationAttemptNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;

/**
 * Inscription sans énumération des comptes.
 *
 * - Adresse libre : le compte est créé (non vérifié, non connecté) et l'événement Registered
 *   envoie le lien de vérification par la file d'attente.
 * - Adresse déjà utilisée : rien n'est créé ni modifié, et le titulaire reçoit
 *   RegistrationAttemptNotification, aussi par la file d'attente.
 *
 * L'appelant renvoie la même réponse dans les deux cas. Le mot de passe est haché dans les
 * deux cas pour que le temps de traitement soit comparable.
 */
class RegisterAccount
{
    public function __construct(private CreateAccount $createAccount) {}

    /**
     * @param  array{name: string, email: string, password: string, account_type: string, locale?: string, timezone?: string, country?: string|null}  $data  données validées, email en minuscules
     */
    public function handle(#[\SensitiveParameter] array $data): void
    {
        $hashedPassword = Hash::make($data['password']);

        $existing = User::query()->where('email', $data['email'])->first();

        if ($existing !== null) {
            $existing->notify(new RegistrationAttemptNotification);

            return;
        }

        try {
            $user = $this->createAccount->handle([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $hashedPassword,
                ...Arr::only($data, ['locale', 'timezone', 'country']),
            ], AccountType::from($data['account_type']));
        } catch (UniqueConstraintViolationException) {
            // Deux inscriptions simultanées avec la même adresse : la seconde est traitée comme
            // une tentative sur une adresse déjà utilisée.
            User::query()->where('email', $data['email'])->first()?->notify(new RegistrationAttemptNotification);

            return;
        }

        event(new Registered($user));
    }
}
