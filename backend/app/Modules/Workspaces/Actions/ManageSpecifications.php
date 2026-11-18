<?php

namespace App\Modules\Workspaces\Actions;

use App\Models\ServiceCategory;
use App\Models\User;
use App\Modules\Workspaces\Enums\ItemSource;
use App\Modules\Workspaces\Models\Specification;
use App\Modules\Workspaces\Models\Step;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Support\SpecificationSections;
use App\Modules\Workspaces\Support\Workspaces;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Cahiers des charges d'un workspace : création et remplacement complets (titre, catégorie,
 * étape, sections à liste fermée), suppression. Au plus workspaces.limits.specifications par
 * workspace (409). Un cahier issu du copilote garde sa marque IA (ai_generated_at) même
 * modifié.
 */
class ManageSpecifications
{
    public function __construct(private Workspaces $workspaces) {}

    /**
     * @param  array<string, mixed>  $data  données validées
     */
    public function create(User $user, Workspace $workspace, array $data, ItemSource $source = ItemSource::User): Specification
    {
        $this->workspaces->assertWritable($user, $workspace);

        return DB::transaction(function () use ($workspace, $data, $source): Specification {
            $workspace = Workspaces::lock($workspace);
            $max = (int) config('workspaces.limits.specifications');

            if (Specification::query()->where('workspace_id', $workspace->id)->count() >= $max) {
                throw new ConflictHttpException(sprintf('Ce workspace a déjà %d cahiers des charges : c\'est le maximum.', $max));
            }

            $specification = new Specification;
            $specification->forceFill([
                'uuid' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'source' => $source,
                'ai_generated_at' => $source === ItemSource::Copilot ? now() : null,
            ]);
            $this->fillContent($workspace, $specification, $data);
            $specification->save();

            return $specification;
        });
    }

    /**
     * @param  array<string, mixed>  $data  données validées (représentation complète)
     */
    public function replace(User $user, Workspace $workspace, Specification $specification, array $data): Specification
    {
        $this->workspaces->assertWritable($user, $workspace);

        $this->fillContent($workspace, $specification, $data);
        $specification->save();

        return $specification;
    }

    public function delete(User $user, Workspace $workspace, Specification $specification): void
    {
        $this->workspaces->assertWritable($user, $workspace);

        $specification->delete();
    }

    public static function find(Workspace $workspace, string $uuid): Specification
    {
        $specification = Specification::query()->where('workspace_id', $workspace->id)->where('uuid', $uuid)->first();

        if ($specification === null) {
            throw new NotFoundHttpException('Cahier des charges introuvable.');
        }

        return $specification;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fillContent(Workspace $workspace, Specification $specification, array $data): void
    {
        $stepId = null;

        if (isset($data['step']) && is_string($data['step'])) {
            $stepId = Step::query()->where('workspace_id', $workspace->id)->where('uuid', $data['step'])->value('id');

            if ($stepId === null) {
                throw ValidationException::withMessages(['step' => 'Cette étape n\'appartient pas à ce workspace.']);
            }
        }

        $category = isset($data['category']) && is_string($data['category'])
            ? ServiceCategory::query()->active()->where('slug', $data['category'])->value('id')
            : null;

        $specification->forceFill([
            'title' => (string) $data['title'],
            'step_id' => $stepId,
            'service_category_id' => $category,
            'sections' => SpecificationSections::normalize(is_array($data['sections'] ?? null) ? $data['sections'] : []),
        ]);
    }
}
