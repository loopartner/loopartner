<?php

namespace App\Modules\Compliance\Support;

use App\Modules\Accounts\Actions\GetEntitlements;
use App\Modules\Accounts\Support\EntitlementSet;
use App\Modules\Compliance\Exceptions\PremiumFeatureRequired;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\ComplianceSettings;
use App\Support\SecurityLog;

/**
 * Répartition gratuit / premium du module, à partir de l'Action publique GetEntitlements du
 * module Accounts (plan calculé à la lecture : aucune tâche à la fin du premium). Les limites
 * viennent des fonctionnalités compliance.* de config/entitlements.php, selon le plan et le
 * rôle de l'utilisateur.
 *
 * Fin du premium : rien n'est supprimé. Une seule entreprise reste active (celle choisie par
 * l'utilisateur, sinon la plus ancienne) ; les autres sont verrouillées : lisibles, exportables
 * et supprimables, mais sans modification, sans rappel ni abonnement calendrier. Les réglages
 * premium restent enregistrés mais sont ramenés aux valeurs gratuites à la lecture.
 */
final class Entitlements
{
    public function __construct(private GetEntitlements $getEntitlements) {}

    public function for(int $userId): EntitlementSet
    {
        return $this->getEntitlements->handle($userId);
    }

    public function isPremium(int $userId): bool
    {
        return $this->for($userId)->isPremium();
    }

    public function maxCompanies(int $userId): int
    {
        return (int) $this->for($userId)->limit('compliance.companies');
    }

    /**
     * Parcours actifs par entreprise (null : illimité).
     */
    public function maxActiveJourneys(int $userId): ?int
    {
        return $this->for($userId)->limit('compliance.active_journeys');
    }

    /**
     * Entreprise active sans premium (null avec premium : toutes sont actives).
     */
    public function activeCompanyId(int $userId): ?int
    {
        if ($this->isPremium($userId)) {
            return null;
        }

        $chosen = ComplianceSettings::query()->where('user_id', $userId)->value('active_company_id');

        if (is_int($chosen) && Company::query()->whereKey($chosen)->where('user_id', $userId)->exists()) {
            return $chosen;
        }

        $oldest = Company::query()->where('user_id', $userId)->orderBy('id')->value('id');

        return is_int($oldest) ? $oldest : null;
    }

    public function isLocked(Company $company): bool
    {
        $active = $this->activeCompanyId($company->user_id);

        return $active !== null && $active !== $company->id;
    }

    /**
     * Entreprise modifiable ? Sinon 403 premium_required (entreprise en trop après la fin du
     * premium).
     *
     * @throws PremiumFeatureRequired
     */
    public function assertWritable(Company $company): void
    {
        if ($this->isLocked($company)) {
            $this->deny('multiple_companies', $company->user_id);
        }
    }

    /**
     * À appeler quand une limite du plan gratuit est dépassée : 403 premium_required sans
     * premium.
     *
     * @throws PremiumFeatureRequired
     */
    public function require(string $feature, int $userId): void
    {
        if (! $this->isPremium($userId)) {
            $this->deny($feature, $userId);
        }
    }

    /**
     * Fonctionnalité ouverte par les droits (compliance.<feature>), sinon 403
     * premium_required.
     *
     * @throws PremiumFeatureRequired
     */
    public function requireFeature(string $feature, int $userId): void
    {
        if (! $this->for($userId)->allows('compliance.'.$feature)) {
            $this->deny($feature, $userId);
        }
    }

    /**
     * Délais de rappel proposés et nombre de délais permis.
     *
     * @return array{choices: list<int>, max: int}
     */
    public function reminderChoices(int $userId): array
    {
        $entitlements = $this->for($userId);

        return [
            'choices' => $entitlements->choices('compliance.reminder_offsets'),
            'max' => (int) $entitlements->limit('compliance.max_reminder_offsets'),
        ];
    }

    public function allowsCustomThresholdLevels(int $userId): bool
    {
        return $this->for($userId)->allows('compliance.custom_threshold_levels');
    }

    /**
     * Délais de rappel effectifs : ceux des délais enregistrés qui font partie des choix du
     * plan, dans la limite du nombre permis (sans premium : un seul délai, parmi les délais
     * gratuits) ; aucun : délais par défaut.
     *
     * @return list<int>
     */
    public function reminderOffsets(ComplianceSettings $settings): array
    {
        $offsets = array_values(array_unique(array_map('intval', $settings->reminder_offsets)));
        sort($offsets);

        ['choices' => $choices, 'max' => $max] = $this->reminderChoices($settings->user_id);
        $allowed = array_slice(array_values(array_intersect($offsets, $choices)), 0, max(0, $max));

        return $allowed === [] ? array_map('intval', (array) config('compliance.reminders.default_offsets')) : $allowed;
    }

    /**
     * Paliers d'alerte effectifs : sans paliers personnalisés (plan gratuit), les paliers par
     * défaut.
     *
     * @return list<int>
     */
    public function thresholdLevels(ComplianceSettings $settings): array
    {
        $levels = $this->allowsCustomThresholdLevels($settings->user_id)
            ? array_map('intval', $settings->threshold_levels)
            : array_map('intval', (array) config('compliance.thresholds.default_levels'));
        $levels = array_values(array_unique($levels));
        sort($levels);

        return $levels;
    }

    /**
     * @throws PremiumFeatureRequired
     */
    private function deny(string $feature, int $userId): never
    {
        SecurityLog::record('compliance.premium_required', ['user_id' => $userId, 'feature' => $feature]);

        throw new PremiumFeatureRequired($feature);
    }
}
