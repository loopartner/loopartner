<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Modules\Accounts\Actions\CreateAccount;
use App\Modules\Accounts\Enums\AccountType;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(private readonly CreateAccount $createAccount) {}

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        /** @var array{name: string, email: string, password: string, account_type: string} $validated */
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
            // Seuls particulier, prestataire et entrepreneur sont acceptés : jamais redacteur ni admin.
            'account_type' => ['required', 'string', Rule::enum(AccountType::class)],
        ])->validate();

        return $this->createAccount->handle([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ], AccountType::from($validated['account_type']));
    }
}
