<?php

namespace App\Modules\Workspaces\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Support\Templates;
use App\Support\Locale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET /workspace-templates : modèles de projet (Premium), avec "available" pour l'utilisateur
 * connecté. La liste reste lisible en gratuit (le frontend propose le Premium).
 */
class TemplateController extends Controller
{
    public function __invoke(Request $request, Templates $templates): JsonResponse
    {
        Gate::authorize('use', Workspace::class);

        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $templates->present($user, Locale::fromRequest($request))])
            ->header('Vary', 'Accept-Language');
    }
}
