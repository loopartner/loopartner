<?php

namespace App\Support\Onboarding;

use App\Models\User;

/**
 * Étape du parcours d'accueil (noyau, lot 5, décision 67), fournie par le module qui possède
 * les données (déclarée dans config/onboarding.php, dans l'ordre d'affichage). L'état d'une
 * étape est **calculé sur les données réelles** (profil publié, premier workspace…), jamais
 * coché à la main : seule une étape facultative peut être passée.
 */
interface OnboardingStep
{
    /**
     * Rôles concernés (account types et rôles de l'équipe).
     *
     * @return list<string>
     */
    public function roles(): array;

    /**
     * Étape facultative (peut être passée par l'utilisateur) ?
     */
    public function optional(): bool;

    /**
     * L'étape s'applique-t-elle à cet utilisateur aujourd'hui (fonctionnalité ouverte…) ?
     */
    public function applies(User $user): bool;

    public function done(User $user): bool;

    /**
     * Libellé affiché, dans la langue donnée (fr, en).
     */
    public function label(string $locale): string;
}
