<?php

namespace App\Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Compliance\Actions\ManageDeadline;
use App\Modules\Compliance\Enums\DeadlineStatus;
use App\Modules\Compliance\Http\Requests\DeadlineRequest;
use App\Modules\Compliance\Http\Requests\ListDeadlinesRequest;
use App\Modules\Compliance\Http\Resources\DeadlineResource;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\Deadline;
use App\Modules\Compliance\Support\Disclaimer;
use App\Support\Pagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Calendrier des échéances : toutes les entreprises de l'utilisateur, ou une entreprise.
 * L'entreprise ou l'échéance d'un autre utilisateur donne 404 (CompanyPolicy).
 */
class DeadlineController extends Controller
{
    private const RELATIONS = ['company', 'rule', 'tasks.deadline.rule', 'tasks.journey.rule'];

    /**
     * Toutes les entreprises de l'utilisateur, triées par date.
     */
    public function all(ListDeadlinesRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('use', Company::class);
        /** @var User $user */
        $user = $request->user();

        $companies = Company::query()->where('user_id', $user->id)
            ->when($request->filled('company'), fn (Builder $query) => $query->where('uuid', $request->string('company')->toString()))
            ->pluck('id');

        return $this->list($request, Deadline::query()->whereIn('company_id', $companies));
    }

    public function index(ListDeadlinesRequest $request, string $company): AnonymousResourceCollection
    {
        $model = CompanyController::find($company, 'view');

        return $this->list($request, Deadline::query()->where('company_id', $model->id));
    }

    public function store(DeadlineRequest $request, ManageDeadline $manageDeadline): JsonResponse
    {
        /** @var array{title: string, due_on: string, notes?: string|null} $data */
        $data = $request->validated();
        $deadline = $manageDeadline->create($request->company(), $data);

        return (new DeadlineResource($deadline->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function show(string $company, string $deadline): DeadlineResource
    {
        return new DeadlineResource(self::find(CompanyController::find($company, 'view'), $deadline)->load(self::RELATIONS));
    }

    public function update(DeadlineRequest $request, string $company, string $deadline, ManageDeadline $manageDeadline): DeadlineResource
    {
        $model = $manageDeadline->update($request->company(), self::find($request->company(), $deadline), $request->validated());

        return new DeadlineResource($model->load(self::RELATIONS));
    }

    public function complete(string $company, string $deadline, ManageDeadline $manageDeadline): DeadlineResource
    {
        return $this->changeStatus($company, $deadline, DeadlineStatus::Done, $manageDeadline);
    }

    public function skip(string $company, string $deadline, ManageDeadline $manageDeadline): DeadlineResource
    {
        return $this->changeStatus($company, $deadline, DeadlineStatus::Skipped, $manageDeadline);
    }

    public function reopen(string $company, string $deadline, ManageDeadline $manageDeadline): DeadlineResource
    {
        return $this->changeStatus($company, $deadline, DeadlineStatus::Todo, $manageDeadline);
    }

    public function destroy(string $company, string $deadline, ManageDeadline $manageDeadline): Response
    {
        $model = CompanyController::find($company, 'update');
        $manageDeadline->delete($model, self::find($model, $deadline));

        return response()->noContent();
    }

    private function changeStatus(string $company, string $deadline, DeadlineStatus $status, ManageDeadline $manageDeadline): DeadlineResource
    {
        $model = CompanyController::find($company, 'update');

        return new DeadlineResource($manageDeadline->changeStatus($model, self::find($model, $deadline), $status)->load(self::RELATIONS));
    }

    /**
     * @param  Builder<Deadline>  $query
     */
    private function list(ListDeadlinesRequest $request, Builder $query): AnonymousResourceCollection
    {
        $status = $request->validated('status');

        $query->whereDate('due_on', '>=', $request->from())
            ->whereDate('due_on', '<=', $request->to())
            ->when($status === 'overdue', fn (Builder $query) => $query->where('status', DeadlineStatus::Todo->value)->whereDate('due_on', '<', now()->toDateString()))
            ->when(is_string($status) && $status !== 'overdue', fn (Builder $query) => $query->where('status', $status))
            ->with(self::RELATIONS)
            ->orderBy('due_on')
            ->orderBy('id');

        return DeadlineResource::collection($query->paginate(Pagination::perPage($request))->withQueryString())
            ->additional(['disclaimer' => Disclaimer::forRequest($request)]);
    }

    /**
     * Échéance de l'entreprise (404 sinon).
     */
    public static function find(Company $company, string $uuid): Deadline
    {
        $deadline = Deadline::query()->where('company_id', $company->id)->where('uuid', $uuid)->first();
        abort_if($deadline === null, 404, 'Échéance introuvable.');

        return $deadline;
    }
}
