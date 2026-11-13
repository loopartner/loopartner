<?php

namespace App\Modules\Compliance\Actions;

use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\ComplianceSettings;
use App\Modules\Compliance\Support\Entitlements;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Sans premium et avec plusieurs entreprises (après la fin du premium) : choix de l'entreprise
 * qui reste active (modifiable, avec rappels et abonnement calendrier). Avec premium, ou avec
 * une seule entreprise, il n'y a rien à choisir (409).
 */
class ActivateCompany
{
    public function __construct(private Entitlements $entitlements) {}

    /**
     * @throws ConflictHttpException
     */
    public function handle(Company $company): void
    {
        $userId = $company->user_id;

        if ($this->entitlements->isPremium($userId) || Company::query()->where('user_id', $userId)->count() < 2) {
            throw new ConflictHttpException('Toutes vos entreprises sont déjà actives.');
        }

        DB::transaction(function () use ($company, $userId): void {
            $settings = ComplianceSettings::for($userId);
            $settings->forceFill(['active_company_id' => $company->id])->save();
        });
    }
}
