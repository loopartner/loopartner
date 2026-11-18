<?php

namespace App\Modules\Workspaces\Support;

use App\Models\User;
use App\Modules\Workspaces\Models\CopilotEntry;
use App\Modules\Workspaces\Models\CopilotSuggestion;
use App\Modules\Workspaces\Models\Goal;
use App\Modules\Workspaces\Models\Specification;
use App\Modules\Workspaces\Models\Step;
use App\Modules\Workspaces\Models\Task;
use App\Modules\Workspaces\Models\Workspace;

/**
 * Données personnelles du module Workspaces pour l'export général du lot 11 (décision 59 ;
 * droit d'accès et portabilité, RGPD art. 15 et 20). **Interne : aucune route ne l'expose
 * encore** ; le lot 11 la branchera sur le contrat du noyau prévu (PersonalDataSource).
 *
 * Contenu : ses workspaces avec leurs objectifs, étapes, tâches et cahiers des charges, les
 * suggestions du copilote encore conservées, et le décompte de ses générations. Textes chiffrés
 * en base rendus en clair (l'export est remis à la personne elle-même) ; uuids seulement,
 * jamais d'identifiant interne.
 */
final class WorkspacesPersonalData
{
    /**
     * @return array{workspaces: list<array<string, mixed>>, copilot_generations: list<array{kind: string, generated_at: string}>}
     */
    public static function for(User $user): array
    {
        $workspaces = Workspace::query()
            ->where('user_id', $user->id)
            ->with(['goals', 'steps.category', 'steps.tasks', 'specifications.category', 'specifications.step', 'suggestions'])
            ->orderBy('id')
            ->get();

        return [
            'workspaces' => $workspaces->map(fn (Workspace $workspace): array => [
                'uuid' => $workspace->uuid,
                'title' => $workspace->title,
                'description' => $workspace->description,
                'country' => $workspace->country,
                'budget_min' => $workspace->budget_min,
                'budget_max' => $workspace->budget_max,
                'currency' => $workspace->currency,
                'remote_ok' => $workspace->remote_ok,
                'desired_start_date' => $workspace->desired_start_date?->toDateString(),
                'status' => $workspace->status->value,
                'template' => $workspace->template_code,
                'created_at' => $workspace->created_at?->toIso8601ZuluString(),
                'goals' => $workspace->goals->map(fn (Goal $goal): array => [
                    'uuid' => $goal->uuid,
                    'title' => $goal->title,
                    'description' => $goal->description,
                    'target_date' => $goal->target_date?->toDateString(),
                    'achieved_at' => $goal->achieved_at?->toIso8601ZuluString(),
                ])->values()->all(),
                'steps' => $workspace->steps->map(fn (Step $step): array => [
                    'uuid' => $step->uuid,
                    'title' => $step->title,
                    'description' => $step->description,
                    'due_date' => $step->due_date?->toDateString(),
                    'completed_at' => $step->completed_at?->toIso8601ZuluString(),
                    'category' => $step->category?->slug,
                    'source' => $step->source->value,
                    'ai_generated' => $step->ai_generated_at !== null,
                    'tasks' => $step->tasks->map(fn (Task $task): array => [
                        'uuid' => $task->uuid,
                        'title' => $task->title,
                        'notes' => $task->notes,
                        'due_date' => $task->due_date?->toDateString(),
                        'completed_at' => $task->completed_at?->toIso8601ZuluString(),
                        'source' => $task->source->value,
                        'ai_generated' => $task->ai_generated_at !== null,
                    ])->values()->all(),
                ])->values()->all(),
                'specifications' => $workspace->specifications->map(fn (Specification $specification): array => [
                    'uuid' => $specification->uuid,
                    'title' => $specification->title,
                    'category' => $specification->category?->slug,
                    'step' => $specification->step?->uuid,
                    'sections' => SpecificationSections::normalize($specification->sections),
                    'ai_generated' => $specification->ai_generated_at !== null,
                ])->values()->all(),
                'copilot_suggestions' => $workspace->suggestions->map(fn (CopilotSuggestion $suggestion): array => [
                    'uuid' => $suggestion->uuid,
                    'kind' => $suggestion->kind->value,
                    'status' => $suggestion->status->value,
                    'content' => $suggestion->content,
                    'model' => $suggestion->model,
                    'created_at' => $suggestion->created_at?->toIso8601ZuluString(),
                ])->values()->all(),
            ])->values()->all(),
            'copilot_generations' => CopilotEntry::query()->where('user_id', $user->id)->orderBy('id')->get()
                ->map(fn (CopilotEntry $entry): array => ['kind' => $entry->kind->value, 'generated_at' => $entry->generated_at->toIso8601ZuluString()])
                ->values()
                ->all(),
        ];
    }
}
