<?php

use App\Models\User;
use App\Modules\Accounts\Actions\GetEntitlements;
use App\Modules\Blog\Models\Article;

/*
| Droits par fonctionnalité (décision 5 révisée) : GetEntitlements et GET /me/entitlements.
| Les valeurs reproduisent exactement les avantages en place (Blog, Compliance) ; la grille
| config/entitlements.php les gouverne.
*/

/**
 * Modifie une fonctionnalité de la grille (les noms contiennent un point : pas de notation
 * pointée de config()).
 */
function setEntitlement(string $role, string $plan, string $feature, mixed $value): void
{
    $key = "entitlements.roles.{$role}.{$plan}";
    config([$key => [...(array) config($key), $feature => $value]]);
}

function entitledUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

describe('GET /me/entitlements', function (): void {
    it('refuse un visiteur anonyme (401)', function (): void {
        $this->getJson('/api/v1/me/entitlements')->assertUnauthorized();
    });

    it('donne le plan gratuit d\'un entrepreneur, avec les avantages actuels', function (): void {
        $this->actingAs(entitledUser('entrepreneur'))
            ->getJson('/api/v1/me/entitlements')
            ->assertOk()
            ->assertExactJson(['data' => [
                'plan' => 'free',
                'role' => 'entrepreneur',
                'features' => [
                    'blog.article_quota' => 2,
                    'compliance.companies' => 1,
                    'compliance.active_journeys' => 1,
                    'compliance.reminder_offsets' => [3, 7, 14],
                    'compliance.max_reminder_offsets' => 1,
                    'compliance.custom_threshold_levels' => false,
                    'compliance.estimate' => false,
                    'compliance.calendar_feed' => true,
                    'compliance.export' => true,
                    'marketplace.quote_comparison' => false,
                    'marketplace.quote_comparison_export' => false,
                    'workspaces.active' => 1,
                    'workspaces.templates' => false,
                    'copilot.roadmap_generations' => 2,
                    'copilot.specifications' => false,
                    'copilot.monthly_generations' => null,
                    'copilot.daily_generations' => null,
                ],
            ]]);
    });

    it('donne le plan premium d\'un prestataire, avec ses avantages', function (): void {
        $provider = entitledUser('prestataire');
        makePremium($provider);

        $this->actingAs($provider)
            ->getJson('/api/v1/me/entitlements')
            ->assertOk()
            ->assertJsonPath('data.plan', 'premium')
            ->assertJsonPath('data.role', 'prestataire')
            ->assertJsonPath('data.features', [
                'blog.article_quota' => null,
                'compliance.companies' => 5,
                'compliance.active_journeys' => null,
                'compliance.reminder_offsets' => [1, 3, 7, 14, 30],
                'compliance.max_reminder_offsets' => 3,
                'compliance.custom_threshold_levels' => true,
                'compliance.estimate' => true,
                'compliance.calendar_feed' => true,
                'compliance.export' => true,
                'directory.project_early_access' => true,
                'directory.project_access' => true,
                'marketplace.quote_branding' => true,
                'appointments.public_booking_page' => true,
                'appointments.active_types' => 10,
                'appointments.weekly_ranges' => 40,
                'appointments.upcoming_exceptions' => 100,
                'appointments.horizon_days' => 90,
                'appointments.upcoming_appointments' => 200,
                'appointments.reminder_offsets' => [120, 1440, 2880],
                'appointments.max_reminder_offsets' => 2,
            ]);
    });

    it('renvoie un objet vide pour l\'équipe', function (string $role): void {
        $response = $this->actingAs(entitledUser($role))->getJson('/api/v1/me/entitlements')->assertOk();

        expect($response->getContent())->toContain('"features":{}')
            ->and($response->json('data.plan'))->toBe('free');
    })->with(['redacteur', 'admin']);

    it('donne au particulier le comparateur de devis, les workspaces et le copilote selon son plan', function (): void {
        $user = entitledUser('particulier');

        $this->actingAs($user)->getJson('/api/v1/me/entitlements')
            ->assertJsonPath('data.features', [
                'marketplace.quote_comparison' => false,
                'marketplace.quote_comparison_export' => false,
                'workspaces.active' => 1,
                'workspaces.templates' => false,
                'copilot.roadmap_generations' => 2,
                'copilot.specifications' => false,
                'copilot.monthly_generations' => null,
                'copilot.daily_generations' => null,
            ]);

        makePremium($user);
        $this->actingAs($user)->getJson('/api/v1/me/entitlements')
            ->assertJsonPath('data.features', [
                'marketplace.quote_comparison' => true,
                'marketplace.quote_comparison_export' => true,
                'workspaces.active' => 10,
                'workspaces.templates' => true,
                'copilot.roadmap_generations' => null,
                'copilot.specifications' => true,
                'copilot.monthly_generations' => 30,
                'copilot.daily_generations' => 10,
            ]);
    });

    it('ne renvoie aucun identifiant ni donnée personnelle', function (): void {
        $response = $this->actingAs(entitledUser('entrepreneur'))->getJson('/api/v1/me/entitlements');

        expect(array_keys($response->json('data')))->toBe(['plan', 'role', 'features']);
        expectNoPrivateFields($response->json());
    });
});

describe('GetEntitlements', function (): void {
    it('revient au plan gratuit à la fin d\'une période premium', function (): void {
        $user = entitledUser('entrepreneur');
        makePremium($user, ['starts_at' => now()->subMonth(), 'ends_at' => now()->addDay()]);

        expect(app(GetEntitlements::class)->handle($user)->isPremium())->toBeTrue();

        $this->travel(2)->days();

        expect(app(GetEntitlements::class)->handle($user->id))
            ->plan->toBe('free')
            ->and(app(GetEntitlements::class)->handle($user->id)->limit('blog.article_quota'))->toBe(2);
    });

    it('lit chaque référence de la grille pour chaque rôle et chaque plan', function (): void {
        foreach (array_keys((array) config('entitlements.roles')) as $role) {
            foreach ((array) config('entitlements.plans') as $plan) {
                expect(app(GetEntitlements::class)->features($role, $plan))->toBeArray();
            }
        }
    });

    it('refuse une référence de configuration inconnue au lieu de la traiter comme illimitée', function (): void {
        setEntitlement('entrepreneur', 'free', 'blog.article_quota', ['config' => 'blog.quota_mal_ecrit']);

        app(GetEntitlements::class)->handle(entitledUser('entrepreneur'));
    })->throws(InvalidArgumentException::class);

    it('refuse un plan inconnu', function (): void {
        app(GetEntitlements::class)->features('entrepreneur', 'business');
    })->throws(InvalidArgumentException::class);

    it('ferme une fonctionnalité absente de la grille', function (): void {
        $entitlements = app(GetEntitlements::class)->handle(entitledUser('particulier'));

        expect($entitlements->limit('blog.article_quota'))->toBe(0)
            ->and($entitlements->allows('compliance.estimate'))->toBeFalse()
            ->and($entitlements->choices('compliance.reminder_offsets'))->toBe([]);
    });
});

describe('grille appliquée par les modules', function (): void {
    it('applique au Blog le quota de la grille', function (): void {
        setEntitlement('entrepreneur', 'free', 'blog.article_quota', 1);
        $author = articleAuthor();
        $first = articleOf($author);
        $second = articleOf($author);

        asAuthor($author)->postJson("/api/v1/me/articles/{$first->uuid}/submit")->assertAccepted();
        asAuthor($author)->postJson("/api/v1/me/articles/{$second->uuid}/submit")
            ->assertForbidden()
            ->assertJsonPath('code', 'premium_required')
            ->assertJsonPath('quota.limit', 1);

        expect(Article::query()->where('status', 'pending_review')->count())->toBe(1);
    });

    it('applique à Compliance le nombre d\'entreprises de la grille', function (): void {
        verifiedLegalForm();
        setEntitlement('entrepreneur', 'free', 'compliance.companies', 2);
        $user = complianceUser();

        $this->actingAs($user)->postJson('/api/v1/me/compliance/companies', companyPayload())->assertCreated();
        $this->actingAs($user)->postJson('/api/v1/me/compliance/companies', companyPayload(['name' => 'Seconde activité']))->assertCreated();
        $this->actingAs($user)->postJson('/api/v1/me/compliance/companies', companyPayload(['name' => 'Troisième activité']))
            ->assertForbidden()
            ->assertJsonPath('code', 'premium_required');
    });

    it('ouvre l\'estimation de Compliance selon la grille', function (): void {
        verifiedLegalForm();
        $user = complianceUser();
        $company = complianceCompanyOf($user);

        $this->actingAs($user)->getJson("/api/v1/me/compliance/companies/{$company->uuid}/estimate")
            ->assertForbidden()
            ->assertJsonPath('feature', 'estimate');

        setEntitlement('entrepreneur', 'free', 'compliance.estimate', true);

        $this->actingAs($user)->getJson("/api/v1/me/compliance/companies/{$company->uuid}/estimate")->assertOk();
    });
});
