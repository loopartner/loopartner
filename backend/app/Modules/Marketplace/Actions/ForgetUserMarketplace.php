<?php

namespace App\Modules\Marketplace\Actions;

use App\Models\User;
use App\Modules\Marketplace\Enums\QuoteStatus;
use App\Modules\Marketplace\Models\Offer;
use App\Modules\Marketplace\Models\Quote;
use App\Modules\Marketplace\Models\SellerProfile;

/**
 * Traite les données Marketplace d'un utilisateur qui supprime son compte (RGPD). Appelée dans
 * la transaction de DeleteAccount (événement AccountDeleting), avant la suppression de la ligne
 * users.
 *
 * - Offres et identité professionnelle : supprimées.
 * - Devis non acceptés (brouillons, envoyés, refusés, retirés, expirés, remplacés), comme
 *   prestataire ou comme client : supprimés.
 * - Devis acceptés : conservés pour l'autre partie, sans lien avec le compte supprimé
 *   (provider_id ou client_id passent à null), puis supprimés avec leur conversation
 *   (purge des conversations orphelines, décision 47). Ils contiennent l'identité
 *   professionnelle copiée à l'envoi : c'est le contrat de l'autre partie.
 */
class ForgetUserMarketplace
{
    public function handle(User $user): void
    {
        Offer::query()->where('user_id', $user->id)->delete();
        SellerProfile::query()->where('user_id', $user->id)->delete();

        Quote::query()
            ->where(fn ($query) => $query->where('provider_id', $user->id)->orWhere('client_id', $user->id))
            ->where('status', '!=', QuoteStatus::Accepted->value)
            ->delete();
    }
}
