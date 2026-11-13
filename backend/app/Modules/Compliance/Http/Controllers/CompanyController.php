<?php

namespace App\Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Compliance\Actions\ActivateCompany;
use App\Modules\Compliance\Actions\CreateCompany;
use App\Modules\Compliance\Actions\DeleteCompany;
use App\Modules\Compliance\Actions\UpdateCompany;
use App\Modules\Compliance\Http\Requests\StoreCompanyRequest;
use App\Modules\Compliance\Http\Requests\UpdateCompanyRequest;
use App\Modules\Compliance\Http\Resources\CompanyResource;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Support\Disclaimer;
use App\Modules\Compliance\Support\Entitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Entreprises de l'utilisateur connecté (prestataires et entrepreneurs, email vérifié).
 * L'entreprise d'un autre utilisateur donne 404, admins compris (CompanyPolicy).
 */
class CompanyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Company::class);

        $companies = Company::query()->where('user_id', self::user($request)->id)->orderBy('id')->get();

        return CompanyResource::collection($companies)->additional(['disclaimer' => Disclaimer::forRequest($request)]);
    }

    public function store(StoreCompanyRequest $request, CreateCompany $createCompany): JsonResponse
    {
        $company = $createCompany->handle(self::user($request), $request->validated());

        return (new CompanyResource($company))->response()->setStatusCode(201);
    }

    public function show(string $company): CompanyResource
    {
        return new CompanyResource(self::find($company, 'view'));
    }

    public function update(UpdateCompanyRequest $request, UpdateCompany $updateCompany): CompanyResource
    {
        return new CompanyResource($updateCompany->handle($request->company(), $request->validated()));
    }

    public function destroy(string $company, DeleteCompany $deleteCompany): Response
    {
        $deleteCompany->handle(self::find($company, 'delete'));

        return response()->noContent();
    }

    /**
     * Sans premium, avec plusieurs entreprises : choisir celle qui reste active.
     */
    public function activate(string $company, ActivateCompany $activateCompany): CompanyResource
    {
        $model = self::find($company, 'update');
        $activateCompany->handle($model);

        return new CompanyResource($model);
    }

    /**
     * Vue d'ensemble du suivi administratif : offre (gratuit ou premium), limites, entreprises.
     */
    public function overview(Request $request, Entitlements $entitlements): JsonResponse
    {
        Gate::authorize('use', Company::class);
        $user = self::user($request);

        return response()->json([
            'data' => [
                'premium' => $entitlements->isPremium($user->id),
                'companies' => [
                    'count' => Company::query()->where('user_id', $user->id)->count(),
                    'max' => $entitlements->maxCompanies($user->id),
                ],
            ],
            'disclaimer' => Disclaimer::forRequest($request),
        ]);
    }

    /**
     * Entreprise par son uuid, avec l'autorisation demandée (404 si inconnue ou d'un autre
     * utilisateur).
     */
    public static function find(string $uuid, string $ability): Company
    {
        $company = Company::query()->where('uuid', $uuid)->first();
        abort_if($company === null, 404, 'Entreprise introuvable.');
        Gate::authorize($ability, $company);

        return $company;
    }

    private static function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
