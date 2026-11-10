<?php

namespace App\Modules\Accounts\Actions;

use App\Models\User;
use App\Modules\Accounts\Enums\AccountType;
use Illuminate\Support\Facades\DB;

/**
 * Crée un compte utilisateur et lui attribue le rôle de son type de compte, dans une seule transaction.
 */
class CreateAccount
{
    /**
     * @param  array{name: string, email: string, password: string, locale?: string, timezone?: string, country?: string|null}  $attributes
     */
    public function handle(array $attributes, AccountType $accountType): User
    {
        return DB::transaction(function () use ($attributes, $accountType): User {
            $user = User::create($attributes);
            $user->assignRole($accountType->value);

            return $user;
        });
    }
}
