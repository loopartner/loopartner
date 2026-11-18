<?php

namespace Database\Seeders;

use App\Models\ServiceCategory;
use App\Models\User;
use App\Modules\Workspaces\Enums\ItemSource;
use App\Modules\Workspaces\Enums\SuggestionKind;
use App\Modules\Workspaces\Enums\SuggestionStatus;
use App\Modules\Workspaces\Enums\WorkspaceStatus;
use App\Modules\Workspaces\Models\CopilotEntry;
use App\Modules\Workspaces\Models\CopilotSuggestion;
use App\Modules\Workspaces\Models\Goal;
use App\Modules\Workspaces\Models\Step;
use App\Modules\Workspaces\Models\Task;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Support\CopilotPrompts;
use App\Modules\Workspaces\Support\Templates;
use App\Support\Ai\Drivers\FakeAiClient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Données de démonstration du module Workspaces, appelées par DatabaseSeeder UNIQUEMENT si
 * DemoDataGuard l'autorise (poste local). Aucun email, **aucun appel à l'IA** : la suggestion
 * du copilote est écrite ici, marquée « [Démo] » et attribuée au pilote simulé.
 *
 * - particulier@example.com (gratuit) : un workspace « [Démo] Rénover la vitrine de ma
 *   boutique » avec un objectif, trois étapes (dont une issue du copilote, marquée IA), des
 *   tâches faites ou non avec des échéances, une suggestion du copilote en attente, et une
 *   génération déjà décomptée (il lui en reste une gratuite).
 * - entrepreneur@example.com (gratuit) : un workspace copié du modèle « Lancer mon activité ».
 *
 * Idempotent : rien n'est recréé si l'utilisateur a déjà un workspace.
 */
class WorkspacesDemoSeeder extends Seeder
{
    public const DEMO_WORKSPACE_TITLE = '[Démo] Rénover la vitrine de ma boutique';

    public function run(): void
    {
        if (! DemoDataGuard::allowsSeeding()) {
            throw new RuntimeException('Données de démonstration refusées hors du poste local (DemoDataGuard).');
        }

        $particulier = User::query()->where('email', 'particulier@example.com')->first();
        $entrepreneur = User::query()->where('email', 'entrepreneur@example.com')->first();

        if ($particulier !== null && ! Workspace::query()->where('user_id', $particulier->id)->exists()) {
            $this->seedParticulier($particulier);
        }

        if ($entrepreneur !== null && ! Workspace::query()->where('user_id', $entrepreneur->id)->exists()) {
            $this->seedEntrepreneur($entrepreneur);
        }
    }

    private function seedParticulier(User $user): void
    {
        $workspace = $this->workspace($user, self::DEMO_WORKSPACE_TITLE, '[Démo] Moderniser la vitrine et l\'enseigne de ma boutique de fleurs avant le printemps.');

        (new Goal)->forceFill([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'title' => '[Démo] Vitrine prête pour la fête des mères',
            'target_date' => now()->addMonths(4)->toDateString(),
            'position' => 0,
        ])->save();

        $brief = $this->step($workspace, '[Démo] Définir le besoin', null, ItemSource::User, 0, now()->addDays(7)->toDateString());
        $this->task($brief, '[Démo] Prendre des photos de la vitrine actuelle', 0, done: true);
        $this->task($brief, '[Démo] Fixer un budget', 1, dueDate: now()->addDays(5)->toDateString());

        $design = $this->step($workspace, '[Démo] Créer la nouvelle identité visuelle', 'identite-visuelle', ItemSource::Copilot, 1, now()->addDays(30)->toDateString());
        $this->task($design, '[Démo] Rédiger le brief pour le graphiste', 0, source: ItemSource::Copilot);

        $this->step($workspace, '[Démo] Faire poser l\'enseigne', null, ItemSource::User, 2);

        (new CopilotSuggestion)->forceFill([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'kind' => SuggestionKind::Roadmap,
            'status' => SuggestionStatus::Pending,
            'content' => [
                'summary' => '[Démo] Suggestion fictive du copilote, écrite par les données de démonstration.',
                'steps' => [
                    ['title' => '[Démo] Comparer trois devis de signalétique', 'description' => '[Démo] Demander des devis détaillés.', 'category' => $this->category('supports-imprimes'), 'tasks' => ['[Démo] Envoyer le cahier des charges']],
                ],
                'categories' => $this->category('identite-visuelle') === null ? [] : [['slug' => 'identite-visuelle', 'reason' => '[Démo] Pour la nouvelle enseigne.']],
            ],
            'prompt_version' => CopilotPrompts::version(SuggestionKind::Roadmap),
            'provider' => 'fake',
            'model' => FakeAiClient::MODEL,
        ])->save();

        (new CopilotEntry)->forceFill(['user_id' => $user->id, 'kind' => SuggestionKind::Roadmap, 'generated_at' => now()])->save();
    }

    private function seedEntrepreneur(User $user): void
    {
        $workspace = $this->workspace($user, '[Démo] Lancer mon activité de traiteur', '[Démo] Préparer le lancement d\'une activité de traiteur à domicile.');
        $templates = app(Templates::class)->all();

        if (isset($templates['lancer-son-activite'])) {
            app(Templates::class)->apply($workspace, $templates['lancer-son-activite'], 'fr');
            $workspace->forceFill(['template_code' => 'lancer-son-activite'])->save();
        }
    }

    private function workspace(User $user, string $title, string $description): Workspace
    {
        $workspace = new Workspace(['title' => $title, 'description' => $description, 'country' => 'FR', 'budget_min' => 1500, 'budget_max' => 5000, 'currency' => 'EUR', 'remote_ok' => false]);
        $workspace->forceFill([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'status' => WorkspaceStatus::Active,
            'activated_at' => now(),
        ])->save();

        return $workspace;
    }

    private function step(Workspace $workspace, string $title, ?string $category, ItemSource $source, int $position, ?string $dueDate = null): Step
    {
        $step = new Step;
        $step->forceFill([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'title' => $title,
            'due_date' => $dueDate,
            'service_category_id' => $category === null ? null : ServiceCategory::query()->where('slug', $category)->value('id'),
            'source' => $source,
            'ai_generated_at' => $source === ItemSource::Copilot ? now() : null,
            'position' => $position,
        ])->save();

        return $step;
    }

    private function task(Step $step, string $title, int $position, bool $done = false, ?string $dueDate = null, ItemSource $source = ItemSource::User): void
    {
        (new Task)->forceFill([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $step->workspace_id,
            'step_id' => $step->id,
            'title' => $title,
            'due_date' => $dueDate,
            'completed_at' => $done ? now() : null,
            'source' => $source,
            'ai_generated_at' => $source === ItemSource::Copilot ? now() : null,
            'position' => $position,
        ])->save();
    }

    private function category(string $slug): ?string
    {
        return ServiceCategory::query()->active()->where('slug', $slug)->exists() ? $slug : null;
    }
}
