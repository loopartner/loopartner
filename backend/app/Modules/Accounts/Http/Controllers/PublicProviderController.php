<?php

namespace App\Modules\Accounts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounts\Http\Resources\PublicProviderProfileResource;
use App\Modules\Accounts\Models\ProviderProfile;

/**
 * Page publique d'un prestataire : seuls les profils publiés sont visibles.
 */
class PublicProviderController extends Controller
{
    public function show(string $slug): PublicProviderProfileResource
    {
        $profile = ProviderProfile::query()->published()->with(['logo', 'categories'])->where('slug', $slug)->first();

        // abort() plutôt que firstOrFail() : le message 404 ne révèle pas le nom du modèle.
        abort_if($profile === null, 404, 'Prestataire introuvable.');

        return new PublicProviderProfileResource($profile);
    }
}
