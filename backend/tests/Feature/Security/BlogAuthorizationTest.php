<?php

use App\Models\User;
use App\Modules\Blog\Enums\ArticleStatus;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Models\ArticleRevision;

/*
| Blog : autorisation de chaque endpoint (OWASP API1 BOLA, API5 BFLA) contre chaque rôle, un
| autre auteur, un auteur au-delà du quota, un modérateur sans 2FA et un modérateur sur son
| propre article.
*/

/**
 * Endpoints d'un article de l'auteur : méthode, URL (avec {uuid}) et données.
 *
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
 */
function ownArticleEndpoints(): array
{
    return [
        'détail' => ['GET', '/api/v1/me/articles/{uuid}', []],
        'modification' => ['PATCH', '/api/v1/me/articles/{uuid}', ['title' => 'Un titre modifié par un intrus']],
        'suppression' => ['DELETE', '/api/v1/me/articles/{uuid}', []],
        'soumission' => ['POST', '/api/v1/me/articles/{uuid}/submit', []],
        'retrait' => ['POST', '/api/v1/me/articles/{uuid}/withdraw', []],
        'retrait de révision' => ['DELETE', '/api/v1/me/articles/{uuid}/revision', []],
    ];
}

describe('articles de l\'auteur', function (): void {
    it('refuse un visiteur anonyme (401)', function (string $method, string $uri): void {
        $this->json($method, str_replace('{uuid}', '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d', $uri))->assertUnauthorized();
    })->with([
        ['GET', '/api/v1/me/articles'],
        ['POST', '/api/v1/me/articles'],
        ['GET', '/api/v1/me/articles/quota'],
        ...array_map(fn (array $endpoint): array => [$endpoint[0], $endpoint[1]], array_values(ownArticleEndpoints())),
    ]);

    it('refuse un particulier (403)', function (): void {
        $user = projectOwner('particulier');

        asAuthor($user)->getJson('/api/v1/me/articles')->assertForbidden();
        asAuthor($user)->getJson('/api/v1/me/articles/quota')->assertForbidden();
        asAuthor($user)->postJson('/api/v1/me/articles', articlePayload())->assertForbidden();
    });

    it('refuse un prestataire sans profil publié (403, avec un message)', function (): void {
        $user = projectOwner('prestataire');

        asAuthor($user)->postJson('/api/v1/me/articles', articlePayload([], false))
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'profil prestataire'));
    });

    it('refuse un auteur dont l\'email n\'est pas vérifié (403)', function (): void {
        asAuthor(projectOwner('entrepreneur', verified: false))->postJson('/api/v1/me/articles', articlePayload())->assertForbidden();
    });

    it('cache l\'article d\'un autre utilisateur (404), modérateurs compris', function (string $role, string $method, string $uri, array $data): void {
        $article = articleOf(articleAuthor(), 'published');
        pendingRevisionFor($article);

        asAuthor(articleAuthor($role))
            ->json($method, str_replace('{uuid}', $article->uuid, $uri), $data)
            ->assertNotFound();

        expect($article->fresh()->status)->toBe(ArticleStatus::Published)
            ->and(ArticleRevision::query()->where('status', 'pending')->count())->toBe(1);
    })->with(['entrepreneur', 'prestataire', 'redacteur', 'admin'])->with(ownArticleEndpoints());

    it('ne liste que ses propres articles', function (): void {
        $author = articleAuthor();
        $mine = articleOf($author);
        articleOf(articleAuthor());

        asAuthor($author)->getJson('/api/v1/me/articles')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $mine->uuid);
    });

    it('refuse à un auteur gratuit au-delà du quota de soumettre, mais pas de modifier ses articles', function (): void {
        $author = articleAuthor();
        $published = articleOf($author, 'published');
        articleOf($author, 'published');
        $draft = articleOf($author);

        asAuthor($author)->postJson("/api/v1/me/articles/{$draft->uuid}/submit")->assertForbidden()->assertJsonPath('code', 'premium_required');
        asAuthor($author)->patchJson("/api/v1/me/articles/{$draft->uuid}", ['title' => 'Un brouillon toujours modifiable'])->assertOk();
        asAuthor($author)->patchJson("/api/v1/me/articles/{$published->uuid}", ['title' => 'Une révision toujours possible'])->assertAccepted();
    });
});

describe('modération', function (): void {
    it('cache chaque endpoint de modération aux auteurs extérieurs et au particulier (404)', function (string $role): void {
        $article = articleOf(articleAuthor(), 'pending');
        $revision = pendingRevisionFor(articleOf(articleAuthor(), 'published'));
        $user = userWithTwoFactor();
        $user->assignRole($role);

        foreach (moderationEndpoints($article, $revision) as [$method, $uri, $data]) {
            asModerator($user)->json($method, $uri, $data)->assertNotFound();
        }
    })->with(['particulier', 'prestataire', 'entrepreneur']);

    it('refuse chaque endpoint de modération à un modérateur sans 2FA de session (403)', function (string $role): void {
        $article = articleOf(articleAuthor(), 'pending');
        $revision = pendingRevisionFor(articleOf(articleAuthor(), 'published'));
        $moderator = articleAuthor($role);
        // La révision a été créée par une requête avec 2FA : la session ne doit pas en hériter.
        $this->flushSession();

        foreach (moderationEndpoints($article, $revision) as [$method, $uri, $data]) {
            fromFrontend()->actingAs($moderator)->json($method, $uri, $data)->assertForbidden();
        }

        expect($article->fresh()->status)->toBe(ArticleStatus::PendingReview);
    })->with(['redacteur', 'admin']);

    it('refuse à un rédacteur de valider son propre article ou sa propre révision (403)', function (): void {
        $redacteur = articleAuthor('redacteur');
        $article = articleOf(articleAuthor(), 'pending');
        $revision = pendingRevisionFor(articleOf(articleAuthor(), 'published'));
        Article::query()->whereKey([$article->id, $revision->article_id])->update(['user_id' => $redacteur->id]);

        asModerator($redacteur)->postJson("/api/v1/admin/articles/{$article->uuid}/approve", ['version' => 1])->assertForbidden();
        asModerator($redacteur)->postJson("/api/v1/admin/article-revisions/{$revision->uuid}/approve", ['version' => 1])->assertForbidden();
        asModerator($redacteur)->postJson("/api/v1/admin/articles/{$article->uuid}/reject", ['version' => 1, 'reason' => 'Je refuse mon propre article.'])->assertForbidden();

        expect($article->fresh()->status)->toBe(ArticleStatus::PendingReview)
            ->and($revision->fresh()->isPending())->toBeTrue();
    });

    it('répond 404 pour un élément inconnu', function (): void {
        $uuid = '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d';

        asModerator(articleAuthor('redacteur'))->getJson("/api/v1/admin/articles/{$uuid}")->assertNotFound();
        asModerator(articleAuthor('redacteur'))->postJson("/api/v1/admin/article-revisions/{$uuid}/approve", ['version' => 1])->assertNotFound();
    });
});

/**
 * Révision en attente d'un article publié, créée par l'API.
 */
function pendingRevisionFor(Article $article): ArticleRevision
{
    /** @var User $author */
    $author = $article->user;

    asAuthor($author)->patchJson("/api/v1/me/articles/{$article->uuid}", ['title' => 'Un titre révisé pour cet article'])->assertAccepted();

    return ArticleRevision::query()->where('article_id', $article->id)->where('status', 'pending')->sole();
}

/**
 * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
 */
function moderationEndpoints(Article $article, ArticleRevision $revision): array
{
    $reason = ['reason' => 'Un motif de refus suffisamment long.'];

    return [
        ['GET', '/api/v1/admin/blog/queue', []],
        ['GET', "/api/v1/admin/articles/{$article->uuid}", []],
        ['POST', "/api/v1/admin/articles/{$article->uuid}/approve", ['version' => 1]],
        ['POST', "/api/v1/admin/articles/{$article->uuid}/reject", ['version' => 1, ...$reason]],
        ['POST', "/api/v1/admin/articles/{$article->uuid}/unpublish", $reason],
        ['GET', "/api/v1/admin/article-revisions/{$revision->uuid}", []],
        ['POST', "/api/v1/admin/article-revisions/{$revision->uuid}/approve", ['version' => 1]],
        ['POST', "/api/v1/admin/article-revisions/{$revision->uuid}/reject", ['version' => 1, ...$reason]],
    ];
}
