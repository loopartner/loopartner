<?php

namespace App\Modules\Accounts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Accounts\Actions\GetPremiumStatus;
use App\Modules\Accounts\Actions\GrantPremium;
use App\Modules\Accounts\Actions\RevokePremium;
use App\Modules\Accounts\Http\Requests\PremiumGrantRequest;
use App\Modules\Accounts\Http\Resources\PremiumGrantResource;
use App\Modules\Accounts\Models\PremiumGrant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Statut premium attribué à la main par un admin (/admin/premium-grants), en attendant le
 * paiement. Accès : rôle admin et double authentification de session (EnsureModerator) ;
 * 404 pour tout autre rôle. Le compte est désigné par son email : l'id interne n'est jamais
 * exposé, et le journal security n'en garde qu'une empreinte.
 */
class PremiumGrantController extends Controller
{
    /**
     * Historique des périodes d'un compte, de la plus récente à la plus ancienne.
     */
    public function index(PremiumGrantRequest $request, GetPremiumStatus $status): JsonResponse
    {
        $user = User::query()->where('email', $request->validated('email'))->first();

        abort_if($user === null, 404, 'Aucun compte ne correspond à cette adresse.');

        $grants = PremiumGrant::query()->where('user_id', $user->id)->latest('starts_at')->latest('id')->get();

        return response()->json([
            'data' => PremiumGrantResource::collection($grants)->resolve($request),
            'meta' => ['premium' => $status->handle($user->id)],
        ]);
    }

    public function store(PremiumGrantRequest $request, GrantPremium $grantPremium): JsonResponse
    {
        /** @var array{email: string, starts_at?: string|null, ends_at?: string|null, reason: string} $data */
        $data = $request->validated();
        $user = User::query()->where('email', $data['email'])->first()
            ?? throw ValidationException::withMessages(['email' => 'Aucun compte ne correspond à cette adresse.']);

        $grant = $grantPremium->handle(
            $this->admin($request),
            $user,
            isset($data['starts_at']) ? CarbonImmutable::parse($data['starts_at'])->utc() : null,
            isset($data['ends_at']) ? CarbonImmutable::parse($data['ends_at'])->utc() : null,
            $data['reason'],
        );

        return (new PremiumGrantResource($grant))->response()->setStatusCode(201);
    }

    public function revoke(PremiumGrantRequest $request, string $uuid, RevokePremium $revokePremium): PremiumGrantResource
    {
        $grant = PremiumGrant::query()->where('uuid', $uuid)->first();

        abort_if($grant === null, 404, 'Période premium introuvable.');

        return new PremiumGrantResource($revokePremium->handle($this->admin($request), $grant, (string) $request->validated('reason')));
    }

    private function admin(PremiumGrantRequest $request): User
    {
        /** @var User */
        return $request->user();
    }
}
