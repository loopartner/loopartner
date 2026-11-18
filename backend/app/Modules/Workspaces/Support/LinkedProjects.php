<?php

namespace App\Modules\Workspaces\Support;

use App\Models\User;
use App\Modules\Directory\Actions\CreateDraftProject;
use App\Modules\Directory\Actions\DescribeOwnProject;
use App\Modules\Directory\Actions\FindOwnProject;
use App\Modules\Workspaces\Models\Workspace;
use App\Support\SecurityLog;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Lien facultatif entre un workspace et un projet Directory de son propriétaire (décisions 3
 * et 69), par les Actions publiques de Directory (jamais son modèle) :
 * - link() : relie un projet existant (le sien seulement, 422 sinon ; un projet n'est relié
 *   qu'à un workspace, 409) ;
 * - createDraft() : crée un **brouillon** Directory à partir du workspace, puis le relie. Rien
 *   n'est publié : l'utilisateur publie le brouillon lui-même.
 */
final class LinkedProjects
{
    public function __construct(
        private FindOwnProject $findOwnProject,
        private DescribeOwnProject $describeOwnProject,
        private CreateDraftProject $createDraftProject,
    ) {}

    /**
     * @return array{uuid: string, title: string, status: string}|null
     */
    public function describe(Workspace $workspace): ?array
    {
        if ($workspace->linked_project_id === null) {
            return null;
        }

        $owner = $workspace->user;

        return $owner === null ? null : $this->describeOwnProject->handle($workspace->linked_project_id, $owner);
    }

    public function link(User $user, Workspace $workspace, string $projectUuid): Workspace
    {
        $project = $this->findOwnProject->handle($projectUuid, $user);

        if ($project === null) {
            throw ValidationException::withMessages(['project' => 'Ce projet n\'existe pas ou ne vous appartient pas.']);
        }

        $this->assertNotLinkedElsewhere($workspace, $project['id']);
        $workspace->forceFill(['linked_project_id' => $project['id']])->save();

        return $workspace;
    }

    public function unlink(Workspace $workspace): Workspace
    {
        $workspace->forceFill(['linked_project_id' => null])->save();

        return $workspace;
    }

    /**
     * @param  array<string, mixed>  $data  category, et facultativement title, description, country
     * @return array{uuid: string, title: string, status: string}
     */
    public function createDraft(User $user, Workspace $workspace, array $data): array
    {
        if ($workspace->linked_project_id !== null) {
            throw new ConflictHttpException('Ce workspace est déjà relié à un projet.');
        }

        $start = $workspace->desired_start_date;
        $draft = $this->createDraftProject->handle($user, [
            'title' => $data['title'] ?? $workspace->title,
            'description' => $data['description'] ?? $workspace->description,
            'category' => $data['category'],
            'country' => $data['country'] ?? $workspace->country ?? $user->country,
            'budget_min' => $workspace->budget_min,
            'budget_max' => $workspace->budget_max,
            'currency' => $workspace->currency,
            'remote_ok' => $workspace->remote_ok,
            'desired_start_date' => $start !== null && $start->isFuture() ? $start->toDateString() : null,
        ]);

        $workspace->forceFill(['linked_project_id' => $draft['id']])->save();
        SecurityLog::record('workspaces.directory_draft_created', ['user_id' => $user->id, 'workspace' => $workspace->uuid, 'project' => $draft['uuid']]);

        return ['uuid' => $draft['uuid'], 'title' => $draft['title'], 'status' => $draft['status']];
    }

    private function assertNotLinkedElsewhere(Workspace $workspace, int $projectId): void
    {
        $taken = Workspace::query()->where('linked_project_id', $projectId)->whereKeyNot($workspace->id)->exists();

        if ($taken) {
            throw new ConflictHttpException('Ce projet est déjà relié à un autre workspace.');
        }
    }
}
