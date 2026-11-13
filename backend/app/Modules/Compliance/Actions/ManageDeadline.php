<?php

namespace App\Modules\Compliance\Actions;

use App\Modules\Compliance\Enums\DeadlineSource;
use App\Modules\Compliance\Enums\DeadlineStatus;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\Deadline;
use App\Modules\Compliance\Support\Entitlements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Échéances d'une entreprise : ajout d'une échéance personnelle, modification, changement
 * d'état (faite, ignorée, à faire), suppression.
 *
 * - Échéance issue d'une règle : seules les notes se modifient (titre et date viennent de la
 *   règle, 422) ; elle ne se supprime pas (409 : l'ignorer à la place).
 * - Échéance personnelle : titre, date et notes modifiables ; suppression possible.
 * - Entreprise verrouillée (après la fin du premium) : 403 premium_required.
 */
class ManageDeadline
{
    public function __construct(private Entitlements $entitlements) {}

    /**
     * @param  array{title: string, due_on: string, notes?: string|null}  $data
     *
     * @throws ConflictHttpException
     */
    public function create(Company $company, array $data): Deadline
    {
        $this->entitlements->assertWritable($company);

        return DB::transaction(function () use ($company, $data): Deadline {
            Company::query()->whereKey($company->id)->lockForUpdate()->first();

            $upcoming = Deadline::query()
                ->where('company_id', $company->id)
                ->where('source', DeadlineSource::Custom->value)
                ->whereDate('due_on', '>=', now()->toDateString())
                ->count();

            if ($upcoming >= (int) config('compliance.max_custom_deadlines')) {
                throw new ConflictHttpException('Vous avez atteint le nombre maximal d\'échéances personnelles à venir pour cette entreprise.');
            }

            $deadline = new Deadline;
            $deadline->forceFill([
                'uuid' => (string) Str::uuid(),
                'company_id' => $company->id,
                'source' => DeadlineSource::Custom,
                'title' => $data['title'],
                'due_on' => $data['due_on'],
                'notes' => $data['notes'] ?? null,
                'status' => DeadlineStatus::Todo,
            ])->save();

            return $deadline;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(Company $company, Deadline $deadline, array $data): Deadline
    {
        $this->entitlements->assertWritable($company);

        if ($deadline->isFromRule()) {
            foreach (['title', 'due_on'] as $field) {
                if (array_key_exists($field, $data)) {
                    throw ValidationException::withMessages([$field => 'Le titre et la date d\'une échéance issue d\'une règle ne se modifient pas : ajoutez une note, ou ignorez-la.']);
                }
            }
        }

        $deadline->forceFill(array_intersect_key($data, array_flip(['title', 'due_on', 'notes'])))->save();

        return $deadline;
    }

    /**
     * @throws ConflictHttpException
     */
    public function changeStatus(Company $company, Deadline $deadline, DeadlineStatus $status): Deadline
    {
        $this->entitlements->assertWritable($company);

        return DB::transaction(function () use ($deadline, $status): Deadline {
            $deadline = Deadline::query()->whereKey($deadline->id)->lockForUpdate()->firstOrFail();

            if ($deadline->status === $status) {
                throw new ConflictHttpException('Cette échéance est déjà dans cet état.');
            }

            $deadline->forceFill([
                'status' => $status,
                'completed_at' => $status === DeadlineStatus::Todo ? null : now(),
            ])->save();

            return $deadline;
        });
    }

    /**
     * @throws ConflictHttpException
     */
    public function delete(Company $company, Deadline $deadline): void
    {
        $this->entitlements->assertWritable($company);

        if ($deadline->isFromRule()) {
            throw new ConflictHttpException('Une échéance issue d\'une règle ne se supprime pas : ignorez-la si elle ne vous concerne pas.');
        }

        $deadline->delete();
    }
}
