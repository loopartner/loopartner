<?php

namespace App\Support\Ai;

use App\Models\AiUsage;
use App\Models\OnboardingState;
use App\Models\User;

/**
 * Données personnelles du noyau ajoutées au lot 5, pour l'export général du lot 11
 * (décision 59). **Interne : aucune route ne l'expose encore.**
 *
 * - ai_usages : ses appels à l'IA encore conservés (fonctionnalité, modèle, jetons, coût
 *   estimé, statut, date) ; jamais de contenu (il n'est stocké nulle part dans ce journal) ;
 * - onboarding_states : ses choix dans le parcours d'accueil.
 */
final class AiUsagePersonalData
{
    /**
     * @return array{ai_usages: list<array<string, mixed>>, onboarding: array<string, mixed>|null}
     */
    public static function for(User $user): array
    {
        $state = OnboardingState::query()->where('user_id', $user->id)->first();

        return [
            'ai_usages' => AiUsage::query()->where('user_id', $user->id)->orderBy('id')->get()
                ->map(fn (AiUsage $usage): array => [
                    'uuid' => $usage->uuid,
                    'feature' => $usage->feature,
                    'provider' => $usage->provider,
                    'model' => $usage->model,
                    'status' => $usage->status,
                    'input_tokens' => $usage->input_tokens,
                    'output_tokens' => $usage->output_tokens,
                    'cost_micros' => $usage->cost_micros,
                    'currency' => (string) config('ai.currency'),
                    'created_at' => $usage->created_at?->toIso8601ZuluString(),
                ])
                ->values()
                ->all(),
            'onboarding' => $state === null ? null : [
                'skipped_steps' => $state->skipped_steps ?? [],
                'dismissed_at' => $state->dismissed_at?->toIso8601ZuluString(),
                'completed_at' => $state->completed_at?->toIso8601ZuluString(),
            ],
        ];
    }
}
