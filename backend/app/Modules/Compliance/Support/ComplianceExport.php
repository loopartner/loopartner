<?php

namespace App\Modules\Compliance\Support;

use App\Models\User;
use App\Modules\Compliance\Models\CalendarFeed;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\ComplianceSettings;
use App\Modules\Compliance\Models\Deadline;
use App\Modules\Compliance\Models\Journey;
use App\Modules\Compliance\Models\RevenueEntry;
use App\Modules\Compliance\Models\Task;
use App\Modules\Compliance\Models\ThresholdAlert;

/**
 * Export complet des données du suivi administratif d'un utilisateur (portabilité, RGPD
 * art. 20), au format JSON : entreprises (profil, numéros), échéances, tâches, parcours,
 * chiffre d'affaires (centimes), alertes de seuil, réglages, état de l'abonnement calendrier
 * (jamais son jeton). Les entreprises verrouillées sont comprises. Aucun identifiant interne.
 *
 * "documents" est réservé au coffre-fort de documents (partie 2 du module).
 */
final class ComplianceExport
{
    /**
     * @return array<string, mixed>
     */
    public static function for(User $user, string $locale): array
    {
        $settings = ComplianceSettings::for($user->id);
        $feed = CalendarFeed::query()->where('user_id', $user->id)->first();

        return [
            'format' => 'loopartner.compliance.export',
            'version' => 1,
            'exported_at' => now()->toIso8601ZuluString(),
            'disclaimer' => Disclaimer::for($locale),
            'settings' => [
                'emails_enabled' => $settings->emails_enabled,
                'reminder_frequency' => $settings->reminder_frequency->value,
                'reminder_offsets' => $settings->reminder_offsets,
                'threshold_levels' => $settings->threshold_levels,
            ],
            'calendar_feed' => $feed === null ? null : [
                'created_at' => $feed->updated_at?->toIso8601ZuluString(),
                'last_accessed_at' => $feed->last_accessed_at?->toIso8601ZuluString(),
            ],
            'companies' => Company::query()->where('user_id', $user->id)->orderBy('id')->get()
                ->map(fn (Company $company): array => self::company($company, $locale))
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function company(Company $company, string $locale): array
    {
        $deadlines = Deadline::query()->where('company_id', $company->id)->with('rule')->orderBy('due_on')->orderBy('id')->get();
        $tasks = Task::query()->where('company_id', $company->id)->with(['deadline.rule', 'journey.rule'])->orderBy('id')->get();

        return [
            'uuid' => $company->uuid,
            'name' => $company->name,
            'country' => $company->country,
            'currency' => RuleCatalog::currency($company->country),
            'legal_form' => $company->legal_form_code,
            'activity_types' => $company->activity_types,
            'vat_regime' => $company->vat_regime,
            'created_on' => $company->created_on->toDateString(),
            'fiscal_year_end' => $company->fiscal_year_end,
            'options' => $company->options,
            'registration_number' => $company->registration_number,
            'vat_number' => $company->vat_number,
            'deadlines' => $deadlines->map(fn (Deadline $deadline): array => [
                'uuid' => $deadline->uuid,
                'source' => $deadline->source->value,
                'rule' => $deadline->rule_code,
                'title' => DeadlineTitle::of($deadline, $locale),
                'due_on' => $deadline->due_on->toDateString(),
                'status' => $deadline->status->value,
                'completed_at' => $deadline->completed_at?->toIso8601ZuluString(),
                'notes' => $deadline->notes,
                'obsolete' => $deadline->obsolete_at !== null,
            ])->all(),
            'tasks' => $tasks->map(fn (Task $task): array => [
                'uuid' => $task->uuid,
                'source' => $task->source->value,
                'title' => $task->title ?? $task->template_key,
                'deadline' => $task->deadline?->uuid,
                'journey' => $task->journey?->uuid,
                'service_category' => $task->service_category_slug,
                'due_on' => $task->due_on?->toDateString(),
                'completed_at' => $task->completed_at?->toIso8601ZuluString(),
            ])->all(),
            'journeys' => Journey::query()->where('company_id', $company->id)->orderBy('id')->get()->map(fn (Journey $journey): array => [
                'uuid' => $journey->uuid,
                'code' => $journey->rule_code,
                'started_at' => $journey->started_at->toIso8601ZuluString(),
                'completed_at' => $journey->completed_at?->toIso8601ZuluString(),
            ])->all(),
            'revenue' => RevenueEntry::query()->where('company_id', $company->id)->orderBy('period')->orderBy('activity_type')->get()->map(fn (RevenueEntry $entry): array => [
                'period' => $entry->period->format('Y-m'),
                'activity_type' => $entry->activity_type,
                'amount' => $entry->amount,
            ])->all(),
            'threshold_alerts' => ThresholdAlert::query()->where('company_id', $company->id)->orderBy('id')->get()->map(fn (ThresholdAlert $alert): array => [
                'rule' => $alert->rule_code,
                'year' => $alert->year,
                'kind' => $alert->kind,
                'level' => $alert->level,
                'reached_at' => $alert->reached_at->toIso8601ZuluString(),
            ])->all(),
            'documents' => [],
        ];
    }
}
