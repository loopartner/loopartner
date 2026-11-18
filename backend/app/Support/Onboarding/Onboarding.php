<?php

namespace App\Support\Onboarding;

use App\Models\OnboardingState;
use App\Models\User;
use App\Support\Locale;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Parcours d'accueil par rôle (noyau, lot 5, décision 67) : étapes déclarées dans
 * config/onboarding.php (clé stable => adaptateur OnboardingStep), filtrées sur le rôle de
 * l'utilisateur, calculées sur les données réelles. Aucun email.
 *
 * - completed : toutes les étapes obligatoires sont faites (date gardée la première fois) ;
 * - une étape facultative peut être passée (skip) ; une obligatoire, jamais (409) ;
 * - le parcours peut être masqué (dismiss), sans rien changer aux étapes.
 */
final class Onboarding
{
    /**
     * @return array<string, OnboardingStep>
     */
    public function stepsFor(User $user): array
    {
        $steps = [];

        foreach ((array) config('onboarding.steps') as $key => $class) {
            $step = app($class);

            if (! $step instanceof OnboardingStep) {
                throw new RuntimeException("Étape d'accueil invalide : {$key}.");
            }

            if ($user->hasAnyRole($step->roles()) && $step->applies($user)) {
                $steps[(string) $key] = $step;
            }
        }

        return $steps;
    }

    /**
     * @return array{steps: list<array{key: string, label: string, optional: bool, done: bool, skipped: bool}>, completed: bool, completed_at: string|null, progress: array{done: int, total: int}, dismissed: bool}
     */
    public function present(User $user, string $locale): array
    {
        $locale = in_array($locale, Locale::SUPPORTED, true) ? $locale : Locale::DEFAULT;
        $state = $this->state($user);
        $skipped = $state->skipped_steps ?? [];
        $items = [];

        foreach ($this->stepsFor($user) as $key => $step) {
            $items[] = [
                'key' => $key,
                'label' => $step->label($locale),
                'optional' => $step->optional(),
                'done' => $step->done($user),
                'skipped' => $step->optional() && in_array($key, $skipped, true),
            ];
        }

        $required = array_filter($items, fn (array $item): bool => ! $item['optional']);
        $completed = array_filter($required, fn (array $item): bool => ! $item['done']) === [];

        if ($completed && $state->completed_at === null) {
            OnboardingState::query()->insertOrIgnore(['user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
            OnboardingState::query()->where('user_id', $user->id)->whereNull('completed_at')->update(['completed_at' => now()]);
            $state = $this->state($user);
        }

        return [
            'steps' => $items,
            'completed' => $completed,
            'completed_at' => $completed ? $state->completed_at?->toIso8601ZuluString() : null,
            'progress' => [
                'done' => count(array_filter($items, fn (array $item): bool => $item['done'] || $item['skipped'])),
                'total' => count($items),
            ],
            'dismissed' => $state->dismissed_at !== null,
        ];
    }

    /**
     * Résumé pour GET /me : parcours terminé (étapes obligatoires faites) et masqué ou non.
     *
     * @return array{completed: bool, dismissed: bool}
     */
    public function summary(User $user): array
    {
        return [
            'completed' => $this->completed($user),
            'dismissed' => OnboardingState::query()->where('user_id', $user->id)->whereNotNull('dismissed_at')->exists(),
        ];
    }

    public function completed(User $user): bool
    {
        foreach ($this->stepsFor($user) as $step) {
            if (! $step->optional() && ! $step->done($user)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Passe une étape facultative : inconnue ou hors du rôle 404, obligatoire 409.
     */
    public function skip(User $user, string $key): void
    {
        $step = $this->stepsFor($user)[$key] ?? throw new NotFoundHttpException('Étape introuvable.');

        if (! $step->optional()) {
            throw new ConflictHttpException('Cette étape est nécessaire : elle ne peut pas être passée.');
        }

        DB::transaction(function () use ($user, $key): void {
            $state = $this->lockedState($user);
            $state->skipped_steps = array_values(array_unique([...($state->skipped_steps ?? []), $key]));
            $state->save();
        });
    }

    public function dismiss(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $state = $this->lockedState($user);
            $state->dismissed_at ??= now();
            $state->save();
        });
    }

    private function state(User $user): OnboardingState
    {
        return OnboardingState::query()->where('user_id', $user->id)->first()
            ?? (new OnboardingState)->forceFill(['user_id' => $user->id]);
    }

    private function lockedState(User $user): OnboardingState
    {
        OnboardingState::query()->insertOrIgnore(['user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

        return OnboardingState::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
    }
}
