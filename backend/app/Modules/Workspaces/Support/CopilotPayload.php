<?php

namespace App\Modules\Workspaces\Support;

use App\Models\ServiceCategory;
use App\Models\User;
use App\Modules\Accounts\Enums\AccountType;
use App\Modules\Workspaces\Enums\SuggestionKind;
use App\Modules\Workspaces\Models\Step;
use App\Modules\Workspaces\Models\Workspace;
use App\Support\Locale;

/**
 * Contenu envoyé à l'IA, construit par **liste blanche** (décision 64 ;
 * docs/v1/workspaces-rules.md, section 3). Le même contenu est montré à l'utilisateur par la
 * prévisualisation, et c'est exactement lui qui part.
 *
 * Envoyé : tâche, langue, type de compte (particulier ou entrepreneur), titre et description du
 * workspace expurgés (Redactor), pays, travail à distance, budget, mois de début souhaité,
 * titres des étapes existantes expurgés, catégories actives (données publiques) ; pour un
 * cahier des charges, l'étape et la catégorie choisies.
 *
 * Jamais : nom, email, identifiant ou uuid de l'utilisateur ou du workspace, ville, objectifs,
 * notes des tâches, ni aucune donnée d'un autre utilisateur ou d'un autre module.
 */
final class CopilotPayload
{
    /**
     * @return array{content: array<string, mixed>, redactions: int}
     */
    public static function build(User $user, Workspace $workspace, SuggestionKind $kind, string $locale, ?Step $step = null, ?ServiceCategory $category = null): array
    {
        $locale = in_array($locale, Locale::SUPPORTED, true) ? $locale : Locale::DEFAULT;
        $redactions = 0;
        $clean = function (?string $text) use (&$redactions): ?string {
            [$redacted, $count] = Redactor::redact($text);
            $redactions += $count;

            return $text === null ? null : $redacted;
        };

        $existingSteps = $workspace->steps()->limit((int) config('workspaces.limits.steps'))->get()
            ->map(fn (Step $existing): string => (string) $clean($existing->title))
            ->values()
            ->all();

        $content = [
            'task' => $kind->value,
            'language' => $locale,
            'account_type' => $user->hasRole(AccountType::Entrepreneur->value) ? AccountType::Entrepreneur->value : AccountType::Particulier->value,
            'project' => [
                'title' => $clean($workspace->title),
                'description' => $clean($workspace->description),
                'country' => $workspace->country,
                'remote_ok' => $workspace->remote_ok,
                'budget' => $workspace->budget_min === null && $workspace->budget_max === null ? null : [
                    'min' => $workspace->budget_min,
                    'max' => $workspace->budget_max,
                    'currency' => $workspace->currency,
                ],
                'desired_start' => $workspace->desired_start_date?->format('Y-m'),
                'existing_steps' => $existingSteps,
            ],
            'categories' => ServiceCategory::query()->active()->ordered()->get()
                ->map(fn (ServiceCategory $active): array => ['slug' => $active->slug, 'name' => $active->nameIn($locale)])
                ->values()
                ->all(),
        ];

        if ($kind === SuggestionKind::Specification) {
            $content['specification'] = [
                'step' => $step === null ? null : $clean($step->title),
                'category' => $category === null ? null : ['slug' => $category->slug, 'name' => $category->nameIn($locale)],
            ];
        }

        return ['content' => $content, 'redactions' => $redactions];
    }

    /**
     * Encodage JSON exact du contenu envoyé.
     *
     * @param  array<string, mixed>  $content
     */
    public static function encode(array $content): string
    {
        return (string) json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
