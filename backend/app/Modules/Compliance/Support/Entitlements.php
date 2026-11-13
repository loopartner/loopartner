<?php

namespace App\Modules\Compliance\Support;

use App\Modules\Accounts\Actions\IsPremium;
use App\Modules\Compliance\Exceptions\PremiumFeatureRequired;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\ComplianceSettings;
use App\Support\SecurityLog;

/**
 * Répartition gratuit / premium du module, à partir de l'Action publique IsPremium du module
 * Accounts (statut calculé à la lecture : aucune tâche à la fin du premium).
 *
 * Fin du premium : rien n'est supprimé. Une seule entreprise reste active (celle choisie par
 * l'utilisateur, sinon la plus ancienne) ; les autres sont verrouillées : lisibles, exportables
 * et supprimables, mais sans modification, sans rappel ni abonnement calendrier. Les réglages
 * premium restent enregistrés mais sont ramenés aux valeurs gratuites à la lecture.
 */
final class Entitlements
{
    public function __construct(private IsPremium $isPremium) {}

    public function isPremium(int $userId): bool
    {
        return $this->isPremium->handle($userId);
    }

    public function maxCompanies(int $userId): int
    {
        return (int) config($this->isPremium($userId) ? 'compliance.max_companies_premium' : 'compliance.max_companies_free');
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
     * @throws PremiumFeatureRequired
     */
    public function require(string $feature, int $userId): void
    {
        if (! $this->isPremium($userId)) {
            $this->deny($feature, $userId);
        }
    }

    /**
     * Délais de rappel effectifs : sans premium, un seul délai, parmi les délais gratuits.
     *
     * @return list<int>
     */
    public function reminderOffsets(ComplianceSettings $settings): array
    {
        $offsets = array_values(array_unique(array_map('intval', $settings->reminder_offsets)));
        sort($offsets);

        if ($this->isPremium($settings->user_id)) {
            return $offsets;
        }

        $free = array_map('intval', (array) config('compliance.reminders.free_offsets'));
        $allowed = array_values(array_intersect($offsets, $free));

        return $allowed === [] ? array_map('intval', (array) config('compliance.reminders.default_offsets')) : [$allowed[0]];
    }

    /**
     * Paliers d'alerte effectifs : sans premium, les paliers par défaut.
     *
     * @return list<int>
     */
    public function thresholdLevels(ComplianceSettings $settings): array
    {
        $levels = $this->isPremium($settings->user_id)
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
