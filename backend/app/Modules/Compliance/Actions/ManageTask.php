<?php

namespace App\Modules\Compliance\Actions;

use App\Modules\Compliance\Enums\DeadlineSource;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\Deadline;
use App\Modules\Compliance\Models\Journey;
use App\Modules\Compliance\Models\Task;
use App\Modules\Compliance\Support\Entitlements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Tâches d'une entreprise : ajout d'une tâche personnelle (libre ou sur une échéance),
 * modification, coche, suppression.
 *
 * - Tâche issue d'une règle (échéance ou parcours) : elle se coche, ne se modifie pas (422) et
 *   ne se supprime pas (409).
 * - Au plus compliance.max_tasks_per_parent tâches par échéance, et autant de tâches libres.
 * - Cocher la dernière tâche d'un parcours le termine ; décocher une tâche le rouvre.
 */
class ManageTask
{
    public function __construct(private Entitlements $entitlements) {}

    /**
     * @param  array{title: string, deadline?: string|null, due_on?: string|null, service_category?: string|null}  $data
     *
     * @throws ConflictHttpException
     * @throws ValidationException
     */
    public function create(Company $company, array $data): Task
    {
        $this->entitlements->assertWritable($company);

        $deadline = null;

        if (isset($data['deadline'])) {
            $deadline = Deadline::query()->where('company_id', $company->id)->where('uuid', $data['deadline'])->first();

            if ($deadline === null) {
                throw ValidationException::withMessages(['deadline' => 'Cette échéance n\'existe pas pour cette entreprise.']);
            }
        }

        return DB::transaction(function () use ($company, $data, $deadline): Task {
            Company::query()->whereKey($company->id)->lockForUpdate()->first();

            $siblings = Task::query()->where('company_id', $company->id)->whereNull('journey_id')
                ->when($deadline === null, fn ($query) => $query->whereNull('deadline_id'), fn ($query) => $query->where('deadline_id', $deadline?->id));

            if ($siblings->count() >= (int) config('compliance.max_tasks_per_parent')) {
                throw new ConflictHttpException('Vous avez atteint le nombre maximal de tâches ici.');
            }

            $task = new Task;
            $task->forceFill([
                'uuid' => (string) Str::uuid(),
                'company_id' => $company->id,
                'deadline_id' => $deadline?->id,
                'source' => DeadlineSource::Custom,
                'title' => $data['title'],
                'service_category_slug' => $data['service_category'] ?? null,
                'due_on' => $data['due_on'] ?? null,
                'position' => ((int) $siblings->max('position')) + 1,
            ])->save();

            return $task;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(Company $company, Task $task, array $data): Task
    {
        $this->entitlements->assertWritable($company);

        if ($task->source === DeadlineSource::Rule && $data !== []) {
            throw ValidationException::withMessages(['task' => 'Une tâche issue d\'une règle ne se modifie pas : cochez-la, ou ajoutez votre propre tâche.']);
        }

        $attributes = [];

        foreach (['title' => 'title', 'due_on' => 'due_on', 'service_category' => 'service_category_slug'] as $field => $column) {
            if (array_key_exists($field, $data)) {
                $attributes[$column] = $data[$field];
            }
        }

        $task->forceFill($attributes)->save();

        return $task;
    }

    public function setCompleted(Company $company, Task $task, bool $completed): Task
    {
        $this->entitlements->assertWritable($company);

        return DB::transaction(function () use ($task, $completed): Task {
            $task->forceFill(['completed_at' => $completed ? ($task->completed_at ?? now()) : null])->save();

            if ($task->journey_id !== null) {
                $journey = Journey::query()->whereKey($task->journey_id)->lockForUpdate()->first();
                $open = Task::query()->where('journey_id', $task->journey_id)->whereNull('completed_at')->exists();
                $journey?->forceFill(['completed_at' => $open ? null : ($journey->completed_at ?? now())])->save();
            }

            return $task;
        });
    }

    /**
     * @throws ConflictHttpException
     */
    public function delete(Company $company, Task $task): void
    {
        $this->entitlements->assertWritable($company);

        if ($task->source === DeadlineSource::Rule) {
            throw new ConflictHttpException('Une tâche issue d\'une règle ne se supprime pas.');
        }

        $task->delete();
    }
}
