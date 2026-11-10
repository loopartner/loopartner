<?php

use App\Models\User;
use App\Modules\Accounts\Models\ProviderProfile;

function categoryProvider(): User
{
    $user = User::factory()->create();
    $user->assignRole('prestataire');

    return $user;
}

/**
 * @return array<string, mixed>
 */
function categoryProfilePayload(array $overrides = []): array
{
    return array_merge(['display_name' => 'Cabinet Martin'], $overrides);
}

/**
 * @return list<string>
 */
function profileCategorySlugs(User $user): array
{
    return ProviderProfile::query()->where('user_id', $user->id)->sole()
        ->categories()->pluck('slug')->all();
}

beforeEach(function (): void {
    $legal = serviceCategory('juridique', 'Juridique', 'Legal', position: 10);
    serviceCategory('statuts', 'Création et statuts', 'Company formation', $legal);
    serviceCategory('design', 'Design', 'Design', position: 20);
    serviceCategory('marketing', 'Marketing', 'Marketing', position: 30);
    serviceCategory('finance', 'Financement', 'Funding', position: 40);
    serviceCategory('rh', 'Ressources humaines', 'Human resources', position: 50);
    serviceCategory('archive', 'Archivée', 'Archived', active: false);
    $inactiveParent = serviceCategory('ancien', 'Ancien', 'Old', active: false);
    serviceCategory('ancien-enfant', 'Ancien enfant', 'Old child', $inactiveParent);
});

describe('validation', function (): void {
    it('accepte de 1 à 5 catégories, parentes ou sous-catégories', function (array $slugs): void {
        $user = categoryProvider();

        $this->actingAs($user)
            ->putJson('/api/v1/me/provider-profile', categoryProfilePayload(['categories' => $slugs]))
            ->assertCreated();

        expect(profileCategorySlugs($user))->toEqualCanonicalizing($slugs);
    })->with([
        'une' => [['statuts']],
        'cinq' => [['juridique', 'statuts', 'design', 'marketing', 'finance']],
    ]);

    it('refuse une liste vide, trop longue, en double ou d\'un mauvais type', function (mixed $categories, string $errorKey): void {
        $this->actingAs(categoryProvider())
            ->putJson('/api/v1/me/provider-profile', categoryProfilePayload(['categories' => $categories]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$errorKey]);

        expect(ProviderProfile::query()->count())->toBe(0);
    })->with([
        'vide' => [[], 'categories'],
        'null' => [null, 'categories'],
        'six catégories' => [['juridique', 'statuts', 'design', 'marketing', 'finance', 'rh'], 'categories'],
        'doublon' => [['design', 'design'], 'categories.0'],
        'chaîne au lieu d\'une liste' => ['design', 'categories'],
        'objet au lieu d\'une liste' => [['a' => 'design'], 'categories'],
        'élément non textuel' => [[42], 'categories.0'],
    ]);

    it('refuse un slug inconnu ou inactif', function (string $slug): void {
        $this->actingAs(categoryProvider())
            ->putJson('/api/v1/me/provider-profile', categoryProfilePayload(['categories' => ['design', $slug]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['categories.1']);
    })->with([
        'inconnu' => ['inconnue'],
        'inactif' => ['archive'],
        'sous-catégorie d\'un parent inactif' => ['ancien-enfant'],
    ]);
});

describe('mise à jour', function (): void {
    it('laisse les catégories inchangées quand le champ est absent', function (): void {
        $user = categoryProvider();
        $this->actingAs($user)
            ->putJson('/api/v1/me/provider-profile', categoryProfilePayload(['categories' => ['design', 'statuts']]))
            ->assertCreated();

        $this->actingAs($user)
            ->putJson('/api/v1/me/provider-profile', categoryProfilePayload(['headline' => 'Nouvelle accroche']))
            ->assertOk()
            ->assertJsonCount(2, 'data.categories');

        expect(profileCategorySlugs($user))->toEqualCanonicalizing(['design', 'statuts']);
    });

    it('remplace les catégories quand le champ est présent', function (): void {
        $user = categoryProvider();
        $this->actingAs($user)
            ->putJson('/api/v1/me/provider-profile', categoryProfilePayload(['categories' => ['design', 'statuts']]))
            ->assertCreated();

        $this->actingAs($user)
            ->putJson('/api/v1/me/provider-profile', categoryProfilePayload(['categories' => ['marketing']]))
            ->assertOk();

        expect(profileCategorySlugs($user))->toBe(['marketing']);
    });

    it('permet un brouillon sans catégorie', function (): void {
        $user = categoryProvider();

        $this->actingAs($user)
            ->putJson('/api/v1/me/provider-profile', categoryProfilePayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.categories', []);
    });
});

describe('publication', function (): void {
    it('exige au moins une catégorie pour publier', function (): void {
        $user = categoryProvider();

        $this->actingAs($user)
            ->putJson('/api/v1/me/provider-profile', categoryProfilePayload(['status' => 'published']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['categories' => 'au moins une catégorie']);

        expect(ProviderProfile::query()->count())->toBe(0);
    });

    it('exige une catégorie pour publier un brouillon existant qui n\'en a pas', function (): void {
        $user = categoryProvider();
        $this->actingAs($user)->putJson('/api/v1/me/provider-profile', categoryProfilePayload())->assertCreated();

        $this->actingAs($user)
            ->putJson('/api/v1/me/provider-profile', categoryProfilePayload(['status' => 'published']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['categories']);

        $this->actingAs($user)
            ->putJson('/api/v1/me/provider-profile', categoryProfilePayload(['status' => 'published', 'categories' => ['design']]))
            ->assertOk()
            ->assertJsonPath('data.status', 'published');
    });

    it('publie un brouillon qui a déjà des catégories sans les renvoyer', function (): void {
        $user = categoryProvider();
        $this->actingAs($user)
            ->putJson('/api/v1/me/provider-profile', categoryProfilePayload(['categories' => ['design']]))
            ->assertCreated();

        $this->actingAs($user)
            ->putJson('/api/v1/me/provider-profile', categoryProfilePayload(['status' => 'published']))
            ->assertOk()
            ->assertJsonPath('data.status', 'published');
    });
});

describe('exposition', function (): void {
    it('expose les catégories traduites, sans identifiant, dans le profil privé et public', function (): void {
        $user = categoryProvider();
        $this->actingAs($user)
            ->putJson('/api/v1/me/provider-profile', categoryProfilePayload([
                'categories' => ['design', 'statuts'],
                'status' => 'published',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.categories', [
                ['slug' => 'statuts', 'name' => 'Création et statuts'],
                ['slug' => 'design', 'name' => 'Design'],
            ]);

        $this->actingAs($user)
            ->getJson('/api/v1/me/provider-profile?locale=en')
            ->assertJsonPath('data.categories.0', ['slug' => 'statuts', 'name' => 'Company formation']);

        $this->getJson('/api/v1/providers/cabinet-martin', ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.categories', [
                ['slug' => 'statuts', 'name' => 'Company formation'],
                ['slug' => 'design', 'name' => 'Design'],
            ]);
    });
});
