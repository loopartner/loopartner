<?php

namespace App\Modules\Compliance\Actions;

use App\Modules\Compliance\Enums\DeadlineSource;
use App\Modules\Compliance\Enums\RuleKind;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\ComplianceRule;
use App\Modules\Compliance\Models\Journey;
use App\Modules\Compliance\Models\Task;
use App\Modules\Compliance\Support\Entitlements;
use App\Modules\Compliance\Support\RuleCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Parcours types (« créer mon entreprise », « clôturer mon exercice »…) : règles journey
 * vérifiées du pays, dont les étapes deviennent des tâches de l'entreprise.
 *
 * Sans premium : un parcours actif (non terminé) à la fois par entreprise (403
 * premium_required au-delà). Un même parcours ne peut pas être suivi deux fois en même temps
 * (409).
 */
class ManageJourney
{
    public function __construct(private Entitlements $entitlements) {}

    /**
     * @throws ConflictHttpException
     * @throws ValidationException
     */
    public function start(Company $company, string $code): Journey
    {
        $this->entitlements->assertWritable($company);

        $rule = RuleCatalog::find($company->country, $code, CarbonImmutable::now(RuleCatalog::timezone($company->country)));

        if ($rule === null || $rule->kind !== RuleKind::Journey) {
            throw ValidationException::withMessages(['journey' => 'Ce parcours n\'existe pas pour ce pays.']);
        }

        return DB::transaction(function () use ($company, $rule): Journey {
            Company::query()->whereKey($company->id)->lockForUpdate()->first();

            $active = Journey::query()->where('company_id', $company->id)->whereNull('completed_at');

            if ((clone $active)->where('rule_code', $rule->code)->exists()) {
                throw new ConflictHttpException('Vous suivez déjà ce parcours pour cette entreprise.');
            }

            if (! $this->entitlements->isPremium($company->user_id) && $active->count() >= (int) config('compliance.max_active_journeys_free')) {
                $this->entitlements->require('multiple_journeys', $company->user_id);
            }

            $journey = new Journey;
            $journey->forceFill([
                'uuid' => (string) Str::uuid(),
                'company_id' => $company->id,
                'rule_code' => $rule->code,
                'rule_id' => $rule->id,
                'started_at' => now(),
            ])->save();

            self::createSteps($journey, $rule);

            return $journey;
        });
    }

    public function delete(Company $company, Journey $journey): void
    {
        $this->entitlements->assertWritable($company);

        $journey->delete();
    }

    private static function createSteps(Journey $journey, ComplianceRule $rule): void
    {
        foreach (array_values((array) ($rule->payload['steps'] ?? [])) as $position => $step) {
            if (! is_array($step) || ! is_string($step['key'] ?? null)) {
                continue;
            }

            $task = new Task;
            $task->forceFill([
                'uuid' => (string) Str::uuid(),
                'company_id' => $journey->company_id,
                'journey_id' => $journey->id,
                'source' => DeadlineSource::Rule,
                'template_key' => $step['key'],
                'service_category_slug' => is_string($step['service_category'] ?? null) ? $step['service_category'] : null,
                'position' => $position,
            ])->save();
        }
    }
}
