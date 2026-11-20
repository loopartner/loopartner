<?php

use App\Models\OnboardingState;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
| Parcours d'accueil par rôle (noyau, décision 67) : étapes calculées sur les données réelles,
| étapes facultatives passées, parcours masqué ; parcours complet de l'inscription à la
| première tâche (critère d'acceptation du lot 5). Aucun email.
*/

/**
 * @return array<string, bool>
 */
function onboardingSteps(User $user): array
{
    return collect(test()->actingAs($user)->getJson('/api/v1/me/onboarding')->assertOk()->json('data.steps'))
        ->mapWithKeys(fn (array $step): array => [$step['key'] => $step['done']])
        ->all();
}

it('va de l\'inscription à la première tâche', function (): void {
    Notification::fake();

    $this->postJson('/api/v1/auth/register', registrationPayload(['email' => 'porteur@example.com']))->assertAccepted();
    $user = User::query()->where('email', 'porteur@example.com')->sole();

    expect(onboardingSteps($user))->toBe([
        'email_verified' => false,
        'preferences' => false,
        'first_workspace' => false,
        'first_task' => false,
        'publish_project' => false,
    ]);

    $this->get(verificationUrlFor($user))->assertRedirect();
    $this->actingAs($user)->patchJson('/api/v1/me', ['country' => 'FR'])->assertOk();
    $workspace = $this->actingAs($user)->postJson('/api/v1/me/workspaces', ['title' => 'Mon premier projet'])->assertCreated()->json('data.uuid');
    $step = $this->actingAs($user)->postJson('/api/v1/me/workspaces/'.$workspace.'/steps', ['title' => 'Première étape'])->json('data.uuid');

    $this->actingAs($user->fresh())->getJson('/api/v1/me')->assertJsonPath('data.onboarding.completed', false);

    $this->actingAs($user)->postJson('/api/v1/me/workspaces/'.$workspace.'/steps/'.$step.'/tasks', ['title' => 'Ma première tâche'])->assertCreated();

    $user = $user->fresh();
    $response = $this->actingAs($user)->getJson('/api/v1/me/onboarding')->assertOk()
        ->assertJsonPath('data.completed', true)
        ->assertJsonPath('data.progress', ['done' => 4, 'total' => 5]);

    expect($response->json('data.completed_at'))->toBeString()
        ->and(OnboardingState::query()->where('user_id', $user->id)->sole()->completed_at)->not->toBeNull();
    $this->actingAs($user)->getJson('/api/v1/me')->assertJsonPath('data.onboarding', ['completed' => true, 'dismissed' => false]);

    // Seul l'email de vérification est parti : le parcours n'envoie aucun email.
    Notification::assertCount(1);
});

it('adapte les étapes au rôle', function (string $role, array $keys): void {
    $user = projectOwner($role);

    expect(array_keys(onboardingSteps($user)))->toBe($keys);
})->with([
    'prestataire' => ['prestataire', ['email_verified', 'preferences', 'provider_profile', 'seller_profile', 'first_offer', 'availability']],
    'entrepreneur' => ['entrepreneur', ['email_verified', 'preferences', 'first_workspace', 'first_task', 'publish_project']],
    'rédacteur' => ['redacteur', ['email_verified', 'two_factor']],
]);

it('marque le profil prestataire publié comme fait', function (): void {
    expect(onboardingSteps(browsingProvider())['provider_profile'])->toBeTrue();
});

it('passe une étape facultative, refuse de passer une étape obligatoire, et masque le parcours', function (): void {
    $user = workspaceOwner();

    $this->actingAs($user)->postJson('/api/v1/me/onboarding/steps/publish_project/skip')
        ->assertOk()
        ->assertJsonPath('data.steps.4.key', 'publish_project')
        ->assertJsonPath('data.steps.4.skipped', true);

    $this->actingAs($user)->postJson('/api/v1/me/onboarding/steps/first_task/skip')->assertConflict();
    $this->actingAs($user)->postJson('/api/v1/me/onboarding/steps/provider_profile/skip')->assertNotFound();
    $this->actingAs($user)->postJson('/api/v1/me/onboarding/steps/inconnue/skip')->assertNotFound();

    $this->actingAs($user)->postJson('/api/v1/me/onboarding/dismiss')->assertOk()->assertJsonPath('data.dismissed', true);
});

it('verrouille la ligne users avant l\'état du parcours (pas d\'interblocage entre deux clics)', function (string $route): void {
    $user = workspaceOwner();
    $queries = recordQueries();

    $this->actingAs($user)->postJson($route)->assertOk();

    $first = collect($queries)->first(fn (array $query): bool => $query['depth'] > 0 && preg_match('/\b(users|onboarding_states)\b/', str_replace(['"', '`'], '', $query['sql'])) === 1);
    expect($first['sql'])->toContain('users')->not->toContain('onboarding_states');

    if (DB::getDriverName() !== 'sqlite') {
        expect($first['sql'])->toContain('for update');
    }
})->with(['/api/v1/me/onboarding/dismiss', '/api/v1/me/onboarding/steps/publish_project/skip']);

it('traduit les libellés', function (): void {
    $this->actingAs(workspaceOwner())->getJson('/api/v1/me/onboarding?locale=en')
        ->assertOk()
        ->assertJsonPath('data.steps.0.label', 'Confirm your email address');
});

it('refuse un anonyme (401)', function (): void {
    $this->getJson('/api/v1/me/onboarding')->assertUnauthorized();
    $this->postJson('/api/v1/me/onboarding/dismiss')->assertUnauthorized();
});

it('limite le débit des choix du parcours (429)', function (): void {
    config(['security.rate_limits.onboarding_writes.per_hour' => 1]);
    $user = workspaceOwner();

    $this->actingAs($user)->postJson('/api/v1/me/onboarding/dismiss')->assertOk();
    $this->actingAs($user)->postJson('/api/v1/me/onboarding/dismiss')->assertTooManyRequests();
});
