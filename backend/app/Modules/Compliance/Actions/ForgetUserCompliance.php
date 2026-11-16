<?php

namespace App\Modules\Compliance\Actions;

use App\Models\User;
use App\Modules\Compliance\Models\CalendarFeed;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\ComplianceSettings;
use Illuminate\Support\Facades\DB;

/**
 * Suppression d'un compte : TOUTES les données du suivi administratif de l'utilisateur sont
 * supprimées (entreprises et, en cascade, échéances, tâches, parcours, chiffre d'affaires,
 * alertes, rappels ; réglages ; abonnement au calendrier ; récapitulatifs envoyés). Rien n'est
 * conservé ni anonymisé : ces données n'ont d'intérêt que pour leur propriétaire.
 *
 * Appelée de façon synchrone dans la transaction de DeleteAccount (événement AccountDeleting).
 */
class ForgetUserCompliance
{
    public function handle(User $user): void
    {
        // Réglages d'abord : active_company_id référence une entreprise.
        ComplianceSettings::query()->where('user_id', $user->id)->delete();
        Company::query()->where('user_id', $user->id)->delete();
        CalendarFeed::query()->where('user_id', $user->id)->delete();
        DB::table('compliance_digests')->where('user_id', $user->id)->delete();
    }
}
