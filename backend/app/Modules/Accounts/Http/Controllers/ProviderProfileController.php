<?php

namespace App\Modules\Accounts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Accounts\Actions\UpsertProviderProfile;
use App\Modules\Accounts\Http\Requests\UpsertProviderProfileRequest;
use App\Modules\Accounts\Http\Resources\ProviderProfileResource;
use App\Modules\Accounts\Models\ProviderProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Profil prestataire de l'utilisateur connecté (rôle prestataire uniquement).
 */
class ProviderProfileController extends Controller
{
    public function show(Request $request): ProviderProfileResource
    {
        Gate::authorize('viewOwn', ProviderProfile::class);

        /** @var User $user */
        $user = $request->user();

        $profile = ProviderProfile::query()->where('user_id', $user->id)->first();
        abort_if($profile === null, 404, 'Aucun profil prestataire.');

        return new ProviderProfileResource($profile->load(['logo', 'banner', 'categories']));
    }

    public function upsert(UpsertProviderProfileRequest $request, UpsertProviderProfile $upsertProfile): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $existing = ProviderProfile::query()->where('user_id', $user->id)->first();
        if ($existing !== null) {
            Gate::authorize('update', $existing);
        }

        /** @var array{display_name: string, headline?: string|null, bio?: string|null, city?: string|null, country?: string|null, website?: string|null, status?: string, logo?: string|null, categories?: list<string>} $data */
        $data = $request->validated();

        $profile = $upsertProfile->handle($user, $data);

        return (new ProviderProfileResource($profile))
            ->response()
            ->setStatusCode($profile->wasRecentlyCreated ? 201 : 200);
    }
}
