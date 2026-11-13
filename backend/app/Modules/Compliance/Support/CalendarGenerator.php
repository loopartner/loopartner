<?php

namespace App\Modules\Compliance\Support;

use App\Modules\Compliance\Enums\DeadlineSource;
use App\Modules\Compliance\Enums\DeadlineStatus;
use App\Modules\Compliance\Enums\RuleKind;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\ComplianceRule;
use App\Modules\Compliance\Models\Deadline;
use App\Modules\Compliance\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Calendrier personnalisé d'une entreprise, généré à partir des règles d'échéance VÉRIFIÉES de
 * son pays qui s'appliquent à son profil, d'aujourd'hui (fuseau du pays) à
 * compliance.calendar_horizon_months mois.
 *
 * Recalcul (profil modifié, règle vérifiée ou retirée, passage des jours), idempotent :
 * - une échéance à faire, issue d'une règle, est créée, déplacée ou supprimée selon les règles ;
 * - une échéance faite ou ignorée n'est JAMAIS modifiée : si sa règle ne s'applique plus, elle
 *   est seulement marquée obsolète (obsolete_at) ;
 * - une échéance passée et pas faite reste (l'utilisateur doit pouvoir la voir en retard) ;
 * - les échéances personnelles ne sont jamais touchées.
 *
 * Les tâches issues de la règle suivent la règle tant que l'échéance est à faire ; une tâche
 * cochée est gardée.
 */
final class CalendarGenerator
{
    /**
     * @return bool le calendrier a-t-il changé ?
     */
    public static function refresh(Company $company): bool
    {
        $today = CarbonImmutable::now(RuleCatalog::timezone($company->country))->startOfDay();
        $today = CarbonImmutable::parse($today->toDateString());
        $horizon = $today->addMonthsNoOverflow((int) config('compliance.calendar_horizon_months'));

        return DB::transaction(function () use ($company, $today, $horizon): bool {
            $desired = self::desired($company, $today, $horizon);
            $changed = false;

            /** @var array<string, Deadline> $existing */
            $existing = Deadline::query()
                ->where('company_id', $company->id)
                ->where('source', DeadlineSource::Rule->value)
                ->where(fn ($query) => $query
                    ->whereDate('due_on', '>=', $today->toDateString())
                    ->orWhereIn('rule_code', array_unique(array_column($desired, 'code'))))
                ->get()
                ->keyBy(fn (Deadline $deadline): string => $deadline->rule_code.'|'.$deadline->occurrence_key)
                ->all();

            foreach ($desired as $key => $occurrence) {
                $deadline = $existing[$key] ?? null;
                unset($existing[$key]);

                if ($deadline === null) {
                    self::create($company, $occurrence['rule'], $occurrence['key'], $occurrence['date']);
                    $changed = true;

                    continue;
                }

                $changed = self::update($deadline, $occurrence['rule'], $occurrence['date']) || $changed;
            }

            // Échéances à venir dont la règle ne s'applique plus.
            foreach ($existing as $deadline) {
                if ($deadline->due_on->toDateString() < $today->toDateString()) {
                    continue;
                }

                if ($deadline->status === DeadlineStatus::Todo) {
                    $deadline->delete();
                    $changed = true;
                } elseif ($deadline->obsolete_at === null) {
                    $deadline->forceFill(['obsolete_at' => now()])->save();
                    $changed = true;
                }
            }

            $company->forceFill(['calendar_refreshed_at' => now()])->save();

            return $changed;
        });
    }

    /**
     * Occurrences attendues, par « code|clé ».
     *
     * @return array<string, array{code: string, key: string, date: CarbonImmutable, rule: ComplianceRule}>
     */
    private static function desired(Company $company, CarbonImmutable $today, CarbonImmutable $horizon): array
    {
        $desired = [];
        $creationYear = $company->created_on->year;

        foreach (RuleCatalog::overlapping($company->country, RuleKind::Deadline, $today, $horizon) as $rule) {
            if (! RuleConditions::matches($rule->conditions, $company)) {
                continue;
            }

            $from = $today->max(CarbonImmutable::parse($rule->valid_from->toDateString()));
            $to = $rule->valid_until === null ? $horizon : $horizon->min(CarbonImmutable::parse($rule->valid_until->toDateString()));
            $schedule = (array) ($rule->payload['schedule'] ?? []);
            $skipCreationYear = (bool) ($rule->payload['skip_creation_year'] ?? false);

            foreach (DeadlineSchedule::occurrences($schedule, $company, $from, $to) as $occurrence) {
                if ($skipCreationYear && $occurrence['date']->year === $creationYear) {
                    continue;
                }

                $desired[$rule->code.'|'.$occurrence['key']] = [
                    'code' => $rule->code,
                    'key' => $occurrence['key'],
                    'date' => $occurrence['date'],
                    'rule' => $rule,
                ];
            }
        }

        return $desired;
    }

    private static function create(Company $company, ComplianceRule $rule, string $key, CarbonImmutable $date): void
    {
        $deadline = new Deadline;
        $deadline->forceFill([
            'uuid' => (string) Str::uuid(),
            'company_id' => $company->id,
            'source' => DeadlineSource::Rule,
            'rule_code' => $rule->code,
            'occurrence_key' => $key,
            'rule_id' => $rule->id,
            'due_on' => $date->toDateString(),
            'status' => DeadlineStatus::Todo,
        ])->save();

        self::syncTasks($deadline, $rule);
    }

    /**
     * @return bool l'échéance a-t-elle changé ?
     */
    private static function update(Deadline $deadline, ComplianceRule $rule, CarbonImmutable $date): bool
    {
        if ($deadline->status !== DeadlineStatus::Todo) {
            // Faite ou ignorée : jamais modifiée. La règle s'applique de nouveau : plus obsolète.
            if ($deadline->obsolete_at !== null) {
                $deadline->forceFill(['obsolete_at' => null])->save();

                return true;
            }

            return false;
        }

        $deadline->forceFill([
            'due_on' => $date->toDateString(),
            'rule_id' => $rule->id,
            'obsolete_at' => null,
        ]);
        $changed = $deadline->isDirty('due_on') || $deadline->isDirty('obsolete_at');
        $deadline->save();

        return self::syncTasks($deadline, $rule) || $changed;
    }

    /**
     * Tâches issues de la règle : ajout des nouvelles, suppression de celles que la règle n'a
     * plus (sauf si elles sont cochées), ordre de la règle.
     *
     * @return bool les tâches ont-elles changé ?
     */
    private static function syncTasks(Deadline $deadline, ComplianceRule $rule): bool
    {
        $templates = collect((array) ($rule->payload['tasks'] ?? []))->filter(fn ($task): bool => is_array($task) && is_string($task['key'] ?? null))->values();
        $existing = Task::query()->where('deadline_id', $deadline->id)->where('source', DeadlineSource::Rule->value)->get()->keyBy('template_key');
        $changed = false;

        foreach ($templates as $position => $template) {
            /** @var array{key: string, service_category: string|null} $template */
            $task = $existing->pull($template['key']);

            if ($task === null) {
                $task = new Task;
                $task->forceFill([
                    'uuid' => (string) Str::uuid(),
                    'company_id' => $deadline->company_id,
                    'deadline_id' => $deadline->id,
                    'source' => DeadlineSource::Rule,
                    'template_key' => $template['key'],
                ]);
                $changed = true;
            }

            $task->forceFill(['position' => $position, 'service_category_slug' => $template['service_category'] ?? null]);
            $changed = $changed || $task->isDirty();
            $task->save();
        }

        foreach ($existing as $task) {
            /** @var Task $task */
            if ($task->completed_at === null) {
                $task->delete();
                $changed = true;
            }
        }

        return $changed;
    }
}
