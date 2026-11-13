<?php

use App\Modules\Blog\Models\Article;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| Blog : injections dans le corps d'un article passées par l'API, limites de débit, et
| absence de contenu, de motif et de donnée personnelle dans le journal security.
*/

beforeEach(fn () => Storage::fake('public'));

describe('injections dans le corps par l\'API', function (): void {
    it('refuse le corps (422) sans rien enregistrer', function (mixed $body): void {
        asAuthor(articleAuthor())
            ->postJson('/api/v1/me/articles', articlePayload(['body' => $body]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body']);

        expect(Article::query()->count())->toBe(0);
    })->with([
        'HTML brut' => ['<p onclick="alert(1)">Bonjour</p>'],
        'script dans un segment' => [['version' => 1, 'blocks' => [['type' => 'paragraph', 'content' => [['text' => '<script>alert(document.cookie)</script>']]]]]],
        'lien javascript:' => [['version' => 1, 'blocks' => [['type' => 'paragraph', 'content' => [['text' => 'x', 'link' => 'javascript:alert(1)']]]]]],
        'lien data:' => [['version' => 1, 'blocks' => [['type' => 'paragraph', 'content' => [['text' => 'x', 'link' => 'data:text/html,<script>alert(1)</script>']]]]]],
        'attribut d\'événement' => [['version' => 1, 'blocks' => [['type' => 'paragraph', 'onmouseover' => 'alert(1)', 'content' => [['text' => 'x']]]]]],
        'image externe' => [['version' => 1, 'blocks' => [['type' => 'image', 'media' => 'https://tracker.example.com/p.gif', 'alt' => 'Pixel espion']]]],
        'bloc html' => [['version' => 1, 'blocks' => [['type' => 'html', 'html' => '<iframe src="https://exemple.fr"></iframe>']]]],
        'imbrication non autorisée' => [['version' => 1, 'blocks' => [['type' => 'quote', 'content' => [['type' => 'list', 'style' => 'bullet', 'items' => [[['text' => 'x']]]]]]]]],
    ]);

    it('refuse un contenu énorme avant la validation (413)', function (): void {
        $huge = ['version' => 1, 'blocks' => array_fill(0, 200, ['type' => 'paragraph', 'content' => [['text' => str_repeat('a', 2000)]]])];

        asAuthor(articleAuthor())->postJson('/api/v1/me/articles', articlePayload(['body' => $huge]))->assertStatus(413);

        expect(Article::query()->count())->toBe(0);
    });

    it('refuse un corps au-delà du plafond de caractères (422)', function (): void {
        $long = ['version' => 1, 'blocks' => array_fill(0, 25, ['type' => 'paragraph', 'content' => [['text' => str_repeat('a ', 999)]]])];

        asAuthor(articleAuthor())->postJson('/api/v1/me/articles', articlePayload(['body' => $long]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body' => 'caractères']);
    });

    it('refuse le HTML et les caractères invisibles dans les champs texte (422)', function (string $field, string $value): void {
        asAuthor(articleAuthor())->postJson('/api/v1/me/articles', articlePayload([$field => $value]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    })->with([
        ['title', 'Un titre <img src=x onerror=alert(1)>'],
        ['excerpt', str_repeat('Un chapô avec un caractère invisible ', 2)."\u{202E}"],
        ['author_name', '<b>Camille</b>'],
        ['seo_description', 'Une description qui renvoie vers un raccourcisseur bit.ly/abcdef pour tromper les lecteurs.'],
    ]);
});

describe('limites de débit', function (): void {
    it('limite les écritures d\'articles (429)', function (): void {
        config(['security.rate_limits.article_writes.per_hour' => 2]);
        $author = articleAuthor();

        asAuthor($author)->postJson('/api/v1/me/articles', articlePayload())->assertCreated();
        asAuthor($author)->postJson('/api/v1/me/articles', articlePayload())->assertCreated();
        asAuthor($author)->postJson('/api/v1/me/articles', articlePayload())->assertTooManyRequests();
    });

    it('limite les soumissions (429)', function (): void {
        config(['security.rate_limits.article_submissions.per_hour' => 1]);
        $author = articleAuthor();
        makePremium($author);
        $first = articleOf($author);
        $second = articleOf($author);

        asAuthor($author)->postJson("/api/v1/me/articles/{$first->uuid}/submit")->assertAccepted();
        asAuthor($author)->postJson("/api/v1/me/articles/{$second->uuid}/submit")->assertTooManyRequests();
    });
});

describe('journal security', function (): void {
    it('ne contient ni contenu d\'article, ni motif, ni email, ni nom', function (): void {
        Notification::fake();
        $author = articleAuthor();
        $moderator = articleAuthor('redacteur');
        $article = articleOf($author, 'pending', ['title' => 'Titre secret de l\'article']);
        $published = articleOf(articleAuthor(), 'published');

        asAuthor($published->user)->patchJson("/api/v1/me/articles/{$published->uuid}", ['excerpt' => str_repeat('Un chapô révisé et très confidentiel. ', 3)])->assertAccepted();
        asModerator($moderator)->postJson("/api/v1/admin/articles/{$article->uuid}/reject", ['version' => 1, 'reason' => 'Motif confidentiel du refus.'])->assertOk();
        asModerator($moderator)->postJson("/api/v1/admin/articles/{$published->uuid}/unpublish", ['reason' => 'Motif confidentiel de dépublication.'])->assertOk();

        $log = json_encode(array_map(fn ($record) => [$record->message, $record->context], securityLog()));

        expect($log)->toContain('blog.article_rejected')
            ->toContain('blog.revision_submitted')
            ->toContain($article->uuid)
            ->not->toContain('Titre secret')
            ->not->toContain('confidentiel')
            ->not->toContain('Motif')
            ->not->toContain($author->email)
            ->not->toContain($author->name)
            ->not->toContain('statut juridique');
    });
});
