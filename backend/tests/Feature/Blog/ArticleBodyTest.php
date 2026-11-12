<?php

use App\Models\Media;
use App\Modules\Blog\Enums\AuthorKind;
use App\Modules\Blog\Exceptions\InvalidArticleBody;
use App\Modules\Blog\Support\ArticleBody;
use App\Modules\Blog\Support\ArticleBodyPresenter;

/*
| Corps d'un article : document JSON de blocs, liste fermée d'éléments, reconstruit par le
| serveur. Cas nominaux et tentatives d'injection.
*/

/**
 * @param  list<array<string, mixed>>  $blocks
 * @return array{version: int, blocks: list<array<string, mixed>>}
 */
function bodyOf(array $blocks): array
{
    return ['version' => 1, 'blocks' => $blocks];
}

/**
 * @return array<string, mixed>
 */
function paragraphOf(string $text, ?string $link = null, array $marks = []): array
{
    return ['type' => 'paragraph', 'content' => [array_filter(['text' => $text, 'link' => $link, 'marks' => $marks], fn ($value) => $value !== null && $value !== [])]];
}

describe('documents valides', function (): void {
    it('normalise chaque élément autorisé', function (): void {
        $uuid = '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d';

        $document = ArticleBody::parse(bodyOf([
            ['type' => 'heading', 'level' => 2, 'content' => [['text' => 'Pourquoi un plan']]],
            ['type' => 'paragraph', 'content' => [
                ['text' => 'Selon '],
                ['text' => 'l\'INSEE', 'link' => 'https://www.insee.fr/fr/statistiques'],
                ['text' => ', c\'est ', 'marks' => []],
                ['text' => 'essentiel', 'marks' => ['italic', 'bold']],
            ]],
            ['type' => 'list', 'style' => 'ordered', 'items' => [[['text' => 'Un']], [['text' => 'Deux']]]],
            ['type' => 'quote', 'content' => [['text' => 'Mieux vaut prévoir.']], 'attribution' => 'Proverbe'],
            ['type' => 'image', 'media' => $uuid, 'alt' => 'Un graphique', 'caption' => null],
            ['type' => 'divider'],
        ]));

        expect($document['blocks'])->toHaveCount(6)
            ->and($document['blocks'][1]['content'][1])->toBe(['text' => 'l\'INSEE', 'marks' => [], 'link' => 'https://www.insee.fr/fr/statistiques'])
            ->and($document['blocks'][1]['content'][2]['text'])->toBe(', c\'est ')
            ->and($document['blocks'][1]['content'][3]['marks'])->toBe(['bold', 'italic'])
            ->and($document['blocks'][4])->toBe(['type' => 'image', 'media' => $uuid, 'alt' => 'Un graphique', 'caption' => null])
            ->and(ArticleBody::imageUuids($document))->toBe([$uuid])
            ->and(ArticleBody::wordCount($document))->toBe(12)
            ->and(ArticleBody::links($document)[0])->toMatchArray(['link' => 'https://www.insee.fr/fr/statistiques', 'host' => 'www.insee.fr']);
    });

    it('accepte un document vide (brouillon)', function (): void {
        expect(ArticleBody::parse(bodyOf([])))->toBe(ArticleBody::empty());
    });

    it('calcule le temps de lecture, une minute au moins', function (): void {
        expect(ArticleBody::readingMinutes(0))->toBe(1)
            ->and(ArticleBody::readingMinutes(230))->toBe(1)
            ->and(ArticleBody::readingMinutes(231))->toBe(2);
    });
});

describe('injections et éléments interdits', function (): void {
    it('refuse le document', function (mixed $input): void {
        expect(fn () => ArticleBody::parse($input))->toThrow(InvalidArticleBody::class);
    })->with([
        'chaîne HTML' => ['<p>Bonjour</p>'],
        'chaîne Markdown' => ['# Titre'],
        'liste au lieu d\'un objet' => [[['type' => 'paragraph']]],
        'version inconnue' => [['version' => 2, 'blocks' => []]],
        'version en texte' => [['version' => '1', 'blocks' => []]],
        'clé inconnue' => [['version' => 1, 'blocks' => [], 'html' => '<b>x</b>']],
        'blocs non listés' => [['version' => 1, 'blocks' => ['a' => paragraphOf('Bonjour')]]],
    ]);

    it('refuse le bloc', function (array $block): void {
        expect(fn () => ArticleBody::parse(bodyOf([$block])))->toThrow(InvalidArticleBody::class);
    })->with([
        'HTML dans le texte' => [paragraphOf('Bonjour <script>alert(1)</script>')],
        'balise image' => [paragraphOf('<img src=x onerror=alert(1)>')],
        'lien javascript:' => [paragraphOf('Clic', 'javascript:alert(1)')],
        'lien javascript: en majuscules' => [paragraphOf('Clic', 'JaVaScRiPt:alert(1)')],
        'lien data:' => [paragraphOf('Clic', 'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==')],
        'lien http' => [paragraphOf('Clic', 'http://exemple.fr')],
        'lien vers une IP' => [paragraphOf('Clic', 'https://127.0.0.1/admin')],
        'raccourcisseur' => [paragraphOf('Clic', 'https://bit.ly/abc')],
        'lien relatif' => [paragraphOf('Clic', '/admin')],
        'lien en objet' => [['type' => 'paragraph', 'content' => [['text' => 'Clic', 'link' => ['href' => 'https://exemple.fr']]]]],
        'lien refusé écrit dans le texte' => [paragraphOf('Voir bit.ly/abc pour la suite')],
        'attribut d\'événement' => [['type' => 'paragraph', 'onclick' => 'alert(1)', 'content' => [['text' => 'Bonjour']]]],
        'attribut sur un segment' => [['type' => 'paragraph', 'content' => [['text' => 'Bonjour', 'style' => 'color:red']]]],
        'marque inconnue' => [paragraphOf('Bonjour', null, ['underline'])],
        'marque en double' => [paragraphOf('Bonjour', null, ['bold', 'bold'])],
        'type inconnu' => [['type' => 'iframe', 'src' => 'https://exemple.fr']],
        'type html' => [['type' => 'html', 'content' => '<b>x</b>']],
        'titre de niveau 1' => [['type' => 'heading', 'level' => 1, 'content' => [['text' => 'Titre']]]],
        'titre de niveau 4' => [['type' => 'heading', 'level' => 4, 'content' => [['text' => 'Titre']]]],
        'lien dans un titre' => [['type' => 'heading', 'level' => 2, 'content' => [['text' => 'Titre', 'link' => 'https://exemple.fr']]]],
        'liste imbriquée' => [['type' => 'list', 'style' => 'bullet', 'items' => [[['type' => 'list', 'style' => 'bullet', 'items' => [[['text' => 'x']]]]]]]],
        'paragraphe dans une citation' => [['type' => 'quote', 'content' => [paragraphOf('Bonjour')]]],
        'liste vide' => [['type' => 'list', 'style' => 'bullet', 'items' => []]],
        'style de liste inconnu' => [['type' => 'list', 'style' => 'roman', 'items' => [[['text' => 'x']]]]],
        'image par URL externe' => [['type' => 'image', 'media' => 'https://tracker.example.com/pixel.gif', 'alt' => 'Pixel']],
        'image avec src' => [['type' => 'image', 'media' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d', 'src' => 'https://exemple.fr/a.png', 'alt' => 'Photo']],
        'image sans texte alternatif' => [['type' => 'image', 'media' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d']],
        'séparateur avec contenu' => [['type' => 'divider', 'content' => [['text' => 'x']]]],
        'caractère invisible' => [paragraphOf("Bon\u{200B}jour")],
        'contrôle de direction' => [paragraphOf("Facture \u{202E}fdp.exe")],
        'texte vide' => [paragraphOf('')],
        'texte uniquement fait d\'espaces' => [paragraphOf('   ')],
        'texte non textuel' => [['type' => 'paragraph', 'content' => [['text' => ['<b>']]]]],
        'segment trop long' => [paragraphOf(str_repeat('a', 2001))],
    ]);

    it('refuse une image en double', function (): void {
        $image = ['type' => 'image', 'media' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d', 'alt' => 'Une photo'];

        expect(fn () => ArticleBody::parse(bodyOf([$image, $image])))->toThrow(InvalidArticleBody::class);
    });

    it('indique l\'emplacement de l\'erreur sans reprendre le contenu reçu', function (): void {
        try {
            ArticleBody::parse(bodyOf([paragraphOf('Bonjour'), ['type' => 'paragraph', 'content' => [['text' => 'ok'], ['text' => 'x', 'link' => 'javascript:alert(1)']]]]));
            $this->fail('Le document aurait dû être refusé.');
        } catch (InvalidArticleBody $e) {
            expect($e->getMessage())->toStartWith('Bloc 2, segment 2 :')
                ->not->toContain('alert(1)');
        }
    });
});

describe('plafonds', function (): void {
    it('refuse au-delà de chaque plafond', function (string $limit, array $blocks): void {
        config(["blog.body.{$limit}" => 3]);

        expect(fn () => ArticleBody::parse(bodyOf($blocks)))->toThrow(InvalidArticleBody::class);
    })->with([
        'blocs' => ['max_blocks', array_fill(0, 4, ['type' => 'divider'])],
        'caractères' => ['max_characters', [paragraphOf('abcd')]],
        'segments' => ['max_segments', [['type' => 'paragraph', 'content' => array_fill(0, 4, ['text' => 'a'])]]],
        'éléments de liste' => ['max_list_items', [['type' => 'list', 'style' => 'bullet', 'items' => array_fill(0, 4, [['text' => 'a']])]]],
        'liens' => ['max_links', [['type' => 'paragraph', 'content' => array_fill(0, 4, ['text' => 'a', 'link' => 'https://exemple.fr'])]]],
        'images' => ['max_images', array_map(fn (int $i): array => ['type' => 'image', 'media' => sprintf('9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6%d', $i), 'alt' => 'Une photo'], range(0, 3))],
    ]);
});

describe('présentation', function (): void {
    it('donne le rel de chaque lien selon le type d\'auteur, et omet les images disparues', function (): void {
        $document = ArticleBody::parse(bodyOf([
            paragraphOf('Voir le site', 'https://exemple.fr'),
            ['type' => 'list', 'style' => 'bullet', 'items' => [[['text' => 'Lien', 'link' => 'https://exemple.org']]]],
            ['type' => 'image', 'media' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d', 'alt' => 'Une photo disparue'],
        ]));

        $external = ArticleBodyPresenter::present($document, AuthorKind::Provider, []);
        $editorial = ArticleBodyPresenter::present($document, AuthorKind::Editorial, []);

        expect($external['blocks'])->toHaveCount(2)
            ->and($external['blocks'][0]['content'][0]['link'])->toBe(['href' => 'https://exemple.fr', 'rel' => 'nofollow ugc noopener noreferrer'])
            ->and($external['blocks'][1]['items'][0][0]['link']['rel'])->toBe('nofollow ugc noopener noreferrer')
            ->and(ArticleBodyPresenter::present($document, AuthorKind::Entrepreneur, [])['blocks'][0]['content'][0]['link']['rel'])->toBe('nofollow ugc noopener noreferrer')
            ->and($editorial['blocks'][0]['content'][0]['link']['rel'])->toBe('noopener noreferrer');
    });

    it('expose l\'URL et les dimensions d\'une image, jamais son id, son disque ni son chemin', function (): void {
        $media = Media::factory()->create(['width' => 800, 'height' => 600]);
        $document = ArticleBody::parse(bodyOf([['type' => 'image', 'media' => $media->uuid, 'alt' => 'Une photo']]));

        $image = ArticleBodyPresenter::present($document, AuthorKind::Provider, [$media->uuid => $media])['blocks'][0];

        expect($image)->toMatchArray(['type' => 'image', 'media' => $media->uuid, 'url' => $media->url(), 'width' => 800, 'height' => 600])
            ->and($image)->not->toHaveKeys(['id', 'disk', 'path']);
    });
});
