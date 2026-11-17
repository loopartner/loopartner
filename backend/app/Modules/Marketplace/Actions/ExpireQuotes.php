<?php

namespace App\Modules\Marketplace\Actions;

use App\Modules\Marketplace\Enums\QuoteStatus;
use App\Modules\Marketplace\Models\Quote;

/**
 * Passe au statut « expiré » les devis envoyés dont la validité est dépassée (tâche horaire
 * marketplace:expire-quotes). Un devis expiré ne peut plus être accepté (vérifié aussi à
 * l'acceptation, même avant le passage de la tâche) ; le prestataire peut en faire une
 * nouvelle version. Aucun email.
 */
class ExpireQuotes
{
    public function handle(): int
    {
        return Quote::query()
            ->where('status', QuoteStatus::Sent->value)
            ->where('expires_at', '<=', now())
            ->update(['status' => QuoteStatus::Expired->value, 'expired_at' => now()]);
    }
}
