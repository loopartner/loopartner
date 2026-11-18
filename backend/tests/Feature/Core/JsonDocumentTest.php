<?php

use App\Modules\Blog\Support\ArticleContent;
use App\Support\Database\JsonDocument;

/*
| Le type JSON natif de MySQL trie les clés de chaque objet à l'écriture (par longueur, puis par
| octets) : un corps d'article relu en base n'a plus l'ordre du corps reconstruit par
| l'application. SQLite et MariaDB gardent l'ordre et ne montrent pas le problème : ces tests
| reproduisent l'ordre de MySQL à la main.
*/

it('compare deux documents sans tenir compte de l\'ordre des clés des objets', function (): void {
    $written = ['version' => 1, 'blocks' => [['type' => 'paragraph', 'content' => [['text' => 'Bonjour', 'marks' => [], 'link' => null]]]]];
    $readFromMysql = ['blocks' => [['type' => 'paragraph', 'content' => [['link' => null, 'text' => 'Bonjour', 'marks' => []]]]], 'version' => 1];

    expect(JsonDocument::same($written, $readFromMysql))->toBeTrue()
        ->and(JsonDocument::canonical($readFromMysql))->toBe(JsonDocument::canonical($written));
});

it('reste stricte sur les valeurs, les types et l\'ordre des listes', function (): void {
    expect(JsonDocument::same(['a' => 1], ['a' => '1']))->toBeFalse()
        ->and(JsonDocument::same(['a' => null], []))->toBeFalse()
        ->and(JsonDocument::same(['blocks' => ['un', 'deux']], ['blocks' => ['deux', 'un']]))->toBeFalse()
        ->and(JsonDocument::same([['text' => 'a'], ['text' => 'b']], [['text' => 'b'], ['text' => 'a']]))->toBeFalse();
});

it('ne signale pas un corps d\'article inchangé relu dans l\'ordre de MySQL comme modifié', function (): void {
    $body = ['version' => 1, 'blocks' => [['type' => 'heading', 'level' => 2, 'content' => [['text' => 'Titre', 'marks' => [], 'link' => null]]]]];
    $bodyAsReadFromMysql = ['blocks' => [['type' => 'heading', 'level' => 2, 'content' => [['link' => null, 'text' => 'Titre', 'marks' => []]]]], 'version' => 1];

    expect(ArticleContent::changedFields(['title' => 'Titre', 'body' => $bodyAsReadFromMysql], ['title' => 'Titre', 'body' => $body]))->toBe([])
        ->and(ArticleContent::changedFields(['title' => 'Titre', 'body' => $bodyAsReadFromMysql], ['title' => 'Autre', 'body' => $body]))->toBe(['title']);
});
