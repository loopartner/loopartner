<?php

use App\Models\ServiceCategory;
use Database\Seeders\ServiceCategorySeeder;

function category(string $slug, string $fr, string $en, ?ServiceCategory $parent = null, int $position = 0, bool $active = true): ServiceCategory
{
    return ServiceCategory::factory()->create([
        'slug' => $slug,
        'name' => ['fr' => $fr, 'en' => $en],
        'parent_id' => $parent?->id,
        'position' => $position,
        'is_active' => $active,
    ]);
}

describe('GET /categories', function (): void {
    it('renvoie l\'arbre des catégories actives, dans l\'ordre, sans identifiant interne', function (): void {
        $legal = category('juridique', 'Juridique', 'Legal', position: 20);
        category('contrats', 'Contrats', 'Contracts', $legal, 20);
        category('statuts', 'Statuts', 'Articles', $legal, 10);
        category('design', 'Design', 'Design', position: 10);

        $response = $this->getJson('/api/v1/categories')->assertOk();

        expect($response->json('data'))->toBe([
            ['slug' => 'design', 'name' => 'Design', 'children' => []],
            ['slug' => 'juridique', 'name' => 'Juridique', 'children' => [
                ['slug' => 'statuts', 'name' => 'Statuts'],
                ['slug' => 'contrats', 'name' => 'Contrats'],
            ]],
        ]);

        $response->assertHeader('Vary', 'Accept-Language');
        expect($response->getContent())->not->toContain('"id"')->not->toContain('parent_id');
    });

    it('traduit les noms selon le paramètre locale, puis Accept-Language, puis en français', function (): void {
        category('juridique', 'Juridique', 'Legal');

        $this->getJson('/api/v1/categories?locale=en', ['Accept-Language' => 'fr-FR'])
            ->assertJsonPath('data.0.name', 'Legal');
        $this->getJson('/api/v1/categories', ['Accept-Language' => 'en-US,en;q=0.9'])
            ->assertJsonPath('data.0.name', 'Legal');
        $this->getJson('/api/v1/categories?locale=de', ['Accept-Language' => 'en'])
            ->assertJsonPath('data.0.name', 'Legal');
        $this->getJson('/api/v1/categories', ['Accept-Language' => 'de-DE'])
            ->assertJsonPath('data.0.name', 'Juridique');
        $this->getJson('/api/v1/categories', ['Accept-Language' => ''])
            ->assertJsonPath('data.0.name', 'Juridique');
        $this->getJson('/api/v1/categories?locale=fr', ['Accept-Language' => 'en'])
            ->assertJsonPath('data.0.name', 'Juridique');
    });

    it('se replie sur le français si la traduction manque', function (): void {
        ServiceCategory::factory()->create(['slug' => 'juridique', 'name' => ['fr' => 'Juridique']]);

        $this->getJson('/api/v1/categories?locale=en')->assertJsonPath('data.0.name', 'Juridique');
    });

    it('masque les catégories inactives et les sous-catégories d\'un parent inactif', function (): void {
        $legal = category('juridique', 'Juridique', 'Legal');
        category('statuts', 'Statuts', 'Articles', $legal);
        category('contrats', 'Contrats', 'Contracts', $legal, active: false);
        $hidden = category('cache', 'Caché', 'Hidden', active: false);
        category('enfant-cache', 'Enfant caché', 'Hidden child', $hidden);

        $response = $this->getJson('/api/v1/categories')->assertOk();

        expect($response->json('data'))->toBe([
            ['slug' => 'juridique', 'name' => 'Juridique', 'children' => [
                ['slug' => 'statuts', 'name' => 'Statuts'],
            ]],
        ]);
    });

    it('est public', function (): void {
        $this->getJson('/api/v1/categories')->assertOk()->assertExactJson(['data' => []]);
    });
});

describe('modèle', function (): void {
    it('refuse un troisième niveau', function (): void {
        $parent = category('a', 'A', 'A');
        $child = category('b', 'B', 'B', $parent);

        category('c', 'C', 'C', $child);
    })->throws(LogicException::class);

    it('refuse de donner un parent à une catégorie qui a des sous-catégories', function (): void {
        $parent = category('a', 'A', 'A');
        category('b', 'B', 'B', $parent);
        $other = category('c', 'C', 'C');

        $parent->update(['parent_id' => $other->id]);
    })->throws(LogicException::class);

    it('refuse qu\'une catégorie soit son propre parent', function (): void {
        $category = category('a', 'A', 'A');

        $category->update(['parent_id' => $category->id]);
    })->throws(LogicException::class);

    it('considère une sous-catégorie d\'un parent inactif comme inactive', function (): void {
        $parent = category('a', 'A', 'A', active: false);
        category('b', 'B', 'B', $parent);

        expect(ServiceCategory::query()->active()->count())->toBe(0)
            ->and(ServiceCategory::idsWithDescendants('b'))->toBe([]);
    });

    it('inclut les sous-catégories actives dans idsWithDescendants', function (): void {
        $parent = category('a', 'A', 'A');
        $child = category('b', 'B', 'B', $parent);
        category('c', 'C', 'C', $parent, active: false);

        expect(ServiceCategory::idsWithDescendants('a'))->toBe([$parent->id, $child->id])
            ->and(ServiceCategory::idsWithDescendants('b'))->toBe([$child->id])
            ->and(ServiceCategory::idsWithDescendants('inconnue'))->toBe([]);
    });
});

describe('ServiceCategorySeeder', function (): void {
    it('crée l\'arbre de référence et peut être relancé', function (): void {
        $this->seed(ServiceCategorySeeder::class);
        $this->seed(ServiceCategorySeeder::class);

        $expected = count(ServiceCategorySeeder::CATEGORIES)
            + array_sum(array_map(fn (array $category): int => count($category[2]), ServiceCategorySeeder::CATEGORIES));

        expect(ServiceCategory::query()->count())->toBe($expected)
            ->and(ServiceCategory::query()->where('slug', 'photo-video')->sole()->parent?->slug)->toBe('marketing-communication')
            ->and(ServiceCategory::query()->where('slug', 'traduction-localisation')->sole()->parent_id)->toBeNull();

        $this->getJson('/api/v1/categories?locale=en')
            ->assertOk()
            ->assertJsonCount(count(ServiceCategorySeeder::CATEGORIES), 'data')
            ->assertJsonPath('data.0.slug', 'juridique')
            ->assertJsonPath('data.0.name', 'Legal');
    });

    it('ne modifie pas une catégorie existante', function (): void {
        $this->seed(ServiceCategorySeeder::class);
        ServiceCategory::query()->where('slug', 'juridique')->update(['is_active' => false, 'name' => json_encode(['fr' => 'Droit', 'en' => 'Law'])]);

        $this->seed(ServiceCategorySeeder::class);

        $legal = ServiceCategory::query()->where('slug', 'juridique')->sole();
        expect($legal->is_active)->toBeFalse()->and($legal->name['fr'])->toBe('Droit');
    });
});
