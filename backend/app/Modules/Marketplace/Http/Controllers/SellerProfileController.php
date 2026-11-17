<?php

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Marketplace\Actions\UpsertSellerProfile;
use App\Modules\Marketplace\Http\Requests\UpsertSellerProfileRequest;
use App\Modules\Marketplace\Http\Resources\SellerProfileResource;
use App\Modules\Marketplace\Models\SellerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Identité professionnelle du prestataire connecté (décision 37).
 */
class SellerProfileController extends Controller
{
    public function show(Request $request): SellerProfileResource
    {
        Gate::authorize('manage', SellerProfile::class);

        $profile = SellerProfile::query()->where('user_id', $this->user($request)->id)->first();
        abort_if($profile === null, 404, 'Aucune identité professionnelle.');

        return new SellerProfileResource($profile);
    }

    public function upsert(UpsertSellerProfileRequest $request, UpsertSellerProfile $upsert): JsonResponse
    {
        $profile = $upsert->handle($this->user($request), $request->validated());

        return (new SellerProfileResource($profile))
            ->response()
            ->setStatusCode($profile->wasRecentlyCreated ? 201 : 200);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
