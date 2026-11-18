<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Locale;
use App\Support\Onboarding\Onboarding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Parcours d'accueil de l'utilisateur connecté (noyau, lot 5) : étapes de son rôle, calculées
 * sur ses données ; passer une étape facultative ; masquer le parcours.
 */
class OnboardingController extends Controller
{
    public function __construct(private Onboarding $onboarding) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->onboarding->present($this->user($request), Locale::fromRequest($request))])
            ->header('Vary', 'Accept-Language');
    }

    public function dismiss(Request $request): JsonResponse
    {
        $this->onboarding->dismiss($this->user($request));

        return $this->show($request);
    }

    public function skip(Request $request, string $key): JsonResponse
    {
        $this->onboarding->skip($this->user($request), $key);

        return $this->show($request);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
