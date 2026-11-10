<?php

namespace App\Modules\Accounts\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Événement public du module Accounts : un compte va être supprimé.
 *
 * Envoyé de façon synchrone par DeleteAccount, dans sa transaction et avant la suppression de
 * la ligne users : les autres modules y traitent les données de l'utilisateur (Directory :
 * demandes et conversations). Une exception d'un écouteur annule toute la suppression.
 * Les écouteurs ne doivent pas être mis en file d'attente (ShouldQueue).
 */
class AccountDeleting
{
    use Dispatchable;

    public function __construct(public readonly User $user) {}
}
