<?php

namespace App\Modules\Directory\Actions;

use App\Models\User;
use App\Modules\Accounts\Enums\AccountType;
use App\Modules\Directory\Support\ProjectRules;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Action publique du module Directory : crée un **brouillon** de projet pour l'utilisateur à
 * partir de données venues d'un autre module (Workspaces, décision 69). Mêmes règles que
 * POST /me/projects (ProjectRules, 422 sur le champ concerné) ; jamais publié ici : l'utilisateur
 * le publie par le parcours habituel, et il devient alors signalable comme tout projet.
 *
 * @phpstan-type DraftProject array{id: int, uuid: string, title: string, status: string}
 */
class CreateDraftProject
{
    public function __construct(private CreateProject $createProject) {}

    /**
     * @param  array<string, mixed>  $data  title, description, category, country, budget_min, budget_max, currency, remote_ok, desired_start_date
     * @return DraftProject
     *
     * @throws ValidationException
     */
    public function handle(User $user, array $data): array
    {
        if (! $user->hasAnyRole([AccountType::Particulier->value, AccountType::Entrepreneur->value])) {
            throw new AccessDeniedHttpException('Seuls les particuliers et les entrepreneurs publient des projets.');
        }

        $data = array_filter(ProjectRules::normalize($data), fn (mixed $value): bool => $value !== null);
        $validated = Validator::make($data, ProjectRules::rules(partial: false))->validate();

        /** @var array{title: string, description: string, category: string, country: string} $validated */
        $project = $this->createProject->handle($user, $validated);

        return [
            'id' => $project->id,
            'uuid' => $project->uuid,
            'title' => $project->title,
            'status' => $project->status->value,
        ];
    }
}
