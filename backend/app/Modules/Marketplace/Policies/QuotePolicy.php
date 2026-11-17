<?php

namespace App\Modules\Marketplace\Policies;

use App\Models\User;
use App\Modules\Accounts\Enums\AccountType;
use App\Modules\Marketplace\Models\Quote;
use Illuminate\Auth\Access\Response;

/**
 * Devis : seules ses deux parties le voient, le prestataire à tout statut, le client une fois
 * envoyé. Tout autre utilisateur reçoit 404, admins compris (la modération passe par
 * /admin/moderation). Une partie qui tente l'action de l'autre reçoit 403.
 */
class QuotePolicy
{
    private const NOT_FOUND = 'Devis introuvable.';

    /**
     * Rédiger un devis : prestataire (la demande de mise en relation est vérifiée ensuite).
     */
    public function create(User $user): bool
    {
        return $user->hasRole(AccountType::Prestataire->value);
    }

    public function view(User $user, Quote $quote): Response
    {
        return $quote->isProvider($user) || $quote->isClient($user)
            ? Response::allow()
            : Response::denyAsNotFound(self::NOT_FOUND);
    }

    /**
     * Modifier, supprimer, envoyer, réviser ou retirer : le prestataire.
     */
    public function manage(User $user, Quote $quote): Response
    {
        return match (true) {
            $quote->isProvider($user) => Response::allow(),
            $quote->isClient($user) => Response::deny('Seul le prestataire peut modifier ce devis.'),
            default => Response::denyAsNotFound(self::NOT_FOUND),
        };
    }

    /**
     * Accepter, refuser, se rétracter ou signaler : le client.
     */
    public function respond(User $user, Quote $quote): Response
    {
        return match (true) {
            $quote->isClient($user) => Response::allow(),
            $quote->isProvider($user) => Response::deny('Seul le client peut répondre à ce devis.'),
            default => Response::denyAsNotFound(self::NOT_FOUND),
        };
    }
}
