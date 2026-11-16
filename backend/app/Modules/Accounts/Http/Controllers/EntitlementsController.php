<?php

namespace App\Modules\Accounts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Accounts\Actions\GetEntitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /me/entitlements : plan actuel, rôle pris en compte et valeur de chaque fonctionnalité
 * (le frontend affiche les limites et propose Premium là où il change quelque chose).
 */
class EntitlementsController extends Controller
{
    public function __invoke(Request $request, GetEntitlements $getEntitlements): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $entitlements = $getEntitlements->handle($user)->toArray();

        // Toujours un objet JSON, même sans aucune fonctionnalité (équipe, particulier).
        return response()->json(['data' => [...$entitlements, 'features' => (object) $entitlements['features']]]);
    }
}
