<?php

use App\Modules\Accounts\Support\Onboarding\ProviderProfileStep;
use App\Modules\Appointments\Support\Onboarding\AvailabilityStep;
use App\Modules\Compliance\Support\Onboarding\CompanyStep;
use App\Modules\Directory\Support\Onboarding\PublishProjectStep;
use App\Modules\Marketplace\Support\Onboarding\FirstOfferStep;
use App\Modules\Marketplace\Support\Onboarding\SellerProfileStep;
use App\Modules\Workspaces\Support\Onboarding\FirstTaskStep;
use App\Modules\Workspaces\Support\Onboarding\FirstWorkspaceStep;
use App\Support\Onboarding\Steps\EmailVerifiedStep;
use App\Support\Onboarding\Steps\PreferencesStep;
use App\Support\Onboarding\Steps\TeamTwoFactorStep;

/*
|--------------------------------------------------------------------------
| Parcours d'accueil (noyau, lot 5, décision 67)
|--------------------------------------------------------------------------
|
| Étapes dans l'ordre d'affichage : clé stable => adaptateur
| (App\Support\Onboarding\OnboardingStep) du module qui possède les données. Chaque étape
| déclare ses rôles ; son état est calculé sur les données réelles. Aucun email.
*/

return [

    'steps' => [
        'email_verified' => EmailVerifiedStep::class,
        'two_factor' => TeamTwoFactorStep::class,
        'preferences' => PreferencesStep::class,
        // Porteurs de projet : de l'inscription à la première tâche.
        'first_workspace' => FirstWorkspaceStep::class,
        'first_task' => FirstTaskStep::class,
        'publish_project' => PublishProjectStep::class,
        // Prestataires.
        'provider_profile' => ProviderProfileStep::class,
        'seller_profile' => SellerProfileStep::class,
        'first_offer' => FirstOfferStep::class,
        'availability' => AvailabilityStep::class,
        // Suivi administratif (si un pays est proposé).
        'company' => CompanyStep::class,
    ],

];
