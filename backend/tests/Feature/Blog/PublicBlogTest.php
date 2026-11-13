<?php

use App\Models\Media;
use App\Modules\Blog\Enums\ArticleStatus;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Models\ArticleRetiredSlug;
use App\Support\Media\MediaPurpose;
use Illuminate\Support\Facades\DB;

/*
| Lecture publique du blog : liste, détail, articles d'un prestataire, sitemap. Seuls les
| articles publiés sont visibles ; aucune donnée privée n'est exposée ; les liens des auteurs
| extérieurs à l'équipe sont toujours en nofollow ugc, premium compris.
*/

/**
 * Corps avec un lien.
 *
 * @return array{version: int, blocks: list<array<string, mixed>>}
 */
function bodyWithLink(string $href = 'https://exemple.fr/offre'): array
{
    return ['version' => 1, 'blocks' => [
        ['type' => 'paragraph', 'content' => [
            ['text' => 'Voir ', 'marks' => [], 'link' => null],
            ['text' => 'notre offre', 'marks' => ['bold'], 'link' => $href],
        ]],
    ]];
}

describe('liste publique', function (): void {
    it('ne liste que les articles publiés, du plus récent au plus ancien', function (): void {
        $author = articleAuthor();
        $older = articleOf($author, 'published', ['published_at' => now()->subDays(5), 'title' => 'Un article plus ancien que les autres']);
        $newer = articleOf(articleAuthor('redacteur'), 'published', ['published_at' => now()->subDay()]);
        articleOf($author);
        articleOf($author, 'pending');
        articleOf($author, 'draft', ['status' => ArticleStatus::Rejected]);
        articleOf($author, 'published', ['status' => ArticleStatus::Unpublished]);

        $response = $this->getJson('/api/v1/articles')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', $newer->slug)
            ->assertJsonPath('data.1.slug', $older->slug)
            ->assertJsonMissingPath('data.0.body')
            ->assertJsonPath('meta.total', 2);

        expectNoPrivateFields($response->json());
        expect($response->json('data.0'))->not->toHaveKeys(['uuid', 'status', 'version', 'author_kind', 'rejection_reason', 'submission_count']);
    });

    it('présente l\'auteur selon son type, sans donnée personnelle', function (): void {
        $provider = articleAuthor('prestataire');
        articleOf($provider, 'published', ['published_at' => now()->subDays(3)]);
        articleOf(articleAuthor(), 'published', ['published_at' => now()->subDays(2), 'author_name' => 'Camille, fondatrice']);
        articleOf(articleAuthor('admin'), 'published', ['published_at' => now()->subDay()]);

        $response = $this->getJson('/api/v1/articles?locale=en')->assertOk();

        expect($response->json('data.0.author'))->toMatchArray(['kind' => 'editorial', 'name' => 'The Loopartner editorial team', 'slug' => null])
            ->and($response->json('data.1.author'))->toMatchArray(['kind' => 'entrepreneur', 'name' => 'Camille, fondatrice', 'slug' => null])
            ->and($response->json('data.2.author.kind'))->toBe('provider')
            ->and($response->json('data.2.author.slug'))->toBe(providerSlug($provider))
            ->and(json_encode($response->json()))->not->toContain($provider->email)
            ->and(json_encode($response->json()))->not->toContain($provider->name);
    });

    it('filtre par catégorie (sous-catégories comprises), langue, auteur et texte', function (): void {
        $parent = serviceCategory('finance');
        $child = serviceCategory('levee-de-fonds', parent: $parent);
        $provider = articleAuthor('prestataire');

        $inChild = articleOf(articleAuthor(), 'published', ['title' => 'Réussir sa levée de fonds en 2027']);
        $inChild->categories()->sync([$child->id]);
        $english = articleOf(articleAuthor(), 'published', ['language' => 'en', 'title' => 'How to price your services']);
        $byProvider = articleOf($provider, 'published', ['title' => 'Le guide du prestataire indépendant']);
        $editorial = articleOf(articleAuthor('redacteur'), 'published', ['title' => 'Les nouveautés de la plateforme']);

        $slugs = fn (string $query): array => collect($this->getJson('/api/v1/articles?'.$query)->assertOk()->json('data'))->pluck('slug')->all();

        expect($slugs('category=finance'))->toBe([$inChild->slug])
            ->and($slugs('language=en'))->toBe([$english->slug])
            ->and($slugs('author='.providerSlug($provider)))->toBe([$byProvider->slug])
            ->and($slugs('author=editorial'))->toBe([$editorial->slug])
            ->and($slugs('author=prestataire-inconnu'))->toBe([])
            ->and($slugs('q=levée'))->toBe([$inChild->slug])
            ->and($slugs('q=100%'))->toBe([])
            ->and($slugs('category='))->toHaveCount(4);
    });

    it('valide les filtres (422)', function (string $query): void {
        $this->getJson('/api/v1/articles?'.$query)->assertUnprocessable();
    })->with([
        'langue inconnue' => 'language=de',
        'recherche trop courte' => 'q=a',
        'recherche trop longue' => 'q='.str_repeat('a', 101),
        'auteur invalide' => 'author=<script>',
        'catégorie invalide' => 'category=../../etc',
        'trop par page' => 'per_page=51',
    ]);
});

describe('détail', function (): void {
    it('montre un article publié avec son corps, ses champs de référencement et des articles liés', function (): void {
        $article = articleOf(articleAuthor(), 'published', ['seo_title' => 'Statut juridique : le guide']);
        $related = articleOf(articleAuthor(), 'published', ['title' => 'Un article lié sur le même sujet']);
        articleOf(articleAuthor(), 'published', ['language' => 'en', 'title' => 'An English article on the topic']);

        $response = $this->getJson("/api/v1/articles/{$article->slug}")
            ->assertOk()
            ->assertHeader('Vary', 'Accept-Language')
            ->assertJsonPath('data.slug', $article->slug)
            ->assertJsonPath('data.seo.title', 'Statut juridique : le guide')
            ->assertJsonPath('data.seo.description', $article->excerpt)
            ->assertJsonPath('data.body.version', 1)
            ->assertJsonPath('data.published_at', $article->published_at->toIso8601ZuluString())
            ->assertJsonCount(1, 'data.related')
            ->assertJsonPath('data.related.0.slug', $related->slug);

        expectNoPrivateFields($response->json());
    });

    it('répond 404 pour un article non publié', function (string $state, array $attributes): void {
        $article = articleOf(articleAuthor(), $state, $attributes);
        $slug = $article->slug ?? 'bien-choisir-le-statut-juridique-de-son-entreprise';

        $this->getJson("/api/v1/articles/{$slug}")->assertNotFound();
    })->with([
        'brouillon' => ['draft', []],
        'en attente' => ['pending', []],
        'dépublié' => ['published', ['status' => ArticleStatus::Unpublished]],
    ]);

    it('répond 410 pour un article supprimé', function (): void {
        $author = articleAuthor();
        $article = articleOf($author, 'published');

        asAuthor($author)->deleteJson("/api/v1/me/articles/{$article->uuid}")->assertNoContent();

        $this->getJson("/api/v1/articles/{$article->slug}")->assertStatus(410);
        expect(ArticleRetiredSlug::query()->count())->toBe(1);
    });

    it('omet les images dont le média a disparu et donne les dimensions des autres', function (): void {
        $author = articleAuthor();
        $image = Media::factory()->create(['user_id' => $author->id, 'purpose' => MediaPurpose::ArticleImage, 'width' => 800, 'height' => 450]);
        $article = articleOf($author, 'published', ['body' => ['version' => 1, 'blocks' => [
            ['type' => 'image', 'media' => $image->uuid, 'alt' => 'Un schéma', 'caption' => null],
            ['type' => 'image', 'media' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d', 'alt' => 'Une image disparue', 'caption' => null],
        ]]]);

        $this->getJson("/api/v1/articles/{$article->slug}")
            ->assertOk()
            ->assertJsonCount(1, 'data.body.blocks')
            ->assertJsonPath('data.body.blocks.0.url', $image->url())
            ->assertJsonPath('data.body.blocks.0.width', 800)
            ->assertJsonMissingPath('data.body.blocks.0.path');
    });
});

describe('liens et référencement', function (): void {
    it('marque nofollow ugc les liens de tout auteur extérieur à l\'équipe, premium compris', function (string $role, bool $premium): void {
        $author = articleAuthor($role);

        if ($premium) {
            makePremium($author);
        }

        $article = articleOf($author, 'published', ['body' => bodyWithLink()]);

        $this->getJson("/api/v1/articles/{$article->slug}")
            ->assertOk()
            ->assertJsonPath('data.body.blocks.0.content.1.link', ['href' => 'https://exemple.fr/offre', 'rel' => 'nofollow ugc noopener noreferrer']);
    })->with([
        'entrepreneur gratuit' => ['entrepreneur', false],
        'entrepreneur premium' => ['entrepreneur', true],
        'prestataire gratuit' => ['prestataire', false],
        'prestataire premium' => ['prestataire', true],
    ]);

    it('donne exactement le même rel à un auteur premium et à un auteur gratuit', function (): void {
        $free = articleOf(articleAuthor(), 'published', ['body' => bodyWithLink()]);
        $premiumAuthor = articleAuthor();
        makePremium($premiumAuthor);
        $paid = articleOf($premiumAuthor, 'published', ['body' => bodyWithLink(), 'title' => 'Un article écrit par un auteur premium']);

        $relOf = fn (Article $article): string => $this->getJson("/api/v1/articles/{$article->slug}")->json('data.body.blocks.0.content.1.link.rel');

        expect($relOf($paid))->toBe($relOf($free))->toBe('nofollow ugc noopener noreferrer');
    });

    it('ne met pas nofollow sur les liens de la rédaction', function (): void {
        $article = articleOf(articleAuthor('redacteur'), 'published', ['body' => bodyWithLink()]);

        $this->getJson("/api/v1/articles/{$article->slug}")
            ->assertJsonPath('data.body.blocks.0.content.1.link.rel', 'noopener noreferrer');
    });
});

describe('articles d\'un prestataire et sitemap', function (): void {
    it('liste les articles publiés d\'un prestataire, 404 si son profil n\'est pas publié', function (): void {
        $provider = articleAuthor('prestataire');
        $published = articleOf($provider, 'published');
        articleOf($provider, 'pending');
        articleOf(articleAuthor(), 'published');

        $this->getJson('/api/v1/providers/'.providerSlug($provider).'/articles')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $published->slug);

        $this->getJson('/api/v1/providers/inconnu/articles')->assertNotFound();
    });

    it('donne la liste légère des articles publiés pour le sitemap', function (): void {
        config(['blog.sitemap_per_page' => 2]);
        $articles = collect(range(1, 3))->map(fn (int $i) => articleOf(articleAuthor(), 'published', ['title' => "Un article numéro {$i} du sitemap"]));
        articleOf(articleAuthor(), 'pending');

        $response = $this->getJson('/api/v1/articles/sitemap')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=3600, public')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);

        expect(array_keys($response->json('data.0')))->toBe(['slug', 'language', 'updated_at'])
            ->and($response->json('data.0.slug'))->toBe($articles[0]->slug);

        $this->getJson('/api/v1/articles/sitemap?page=2')->assertJsonCount(1, 'data');
    });

    it('ne donne jamais le slug « sitemap » à un article', function (): void {
        $author = articleAuthor('redacteur');
        $article = articleOf($author, 'draft', ['title' => 'Sitemap']);

        asAuthor($author)->postJson("/api/v1/me/articles/{$article->uuid}/submit")->assertOk()->assertJsonPath('data.slug', 'article-sitemap');
    });
});

it('n\'expose les catégories que par leur slug et leur nom', function (): void {
    $article = articleOf(articleAuthor(), 'published');

    $this->getJson("/api/v1/articles/{$article->slug}")
        ->assertJsonPath('data.categories.0', ['slug' => 'creation-entreprise', 'name' => 'Création d\'entreprise']);

    expect(DB::table('article_category')->count())->toBe(1);
});
