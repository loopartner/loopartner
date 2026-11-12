<?php

namespace App\Modules\Blog\Support;

use App\Modules\Blog\Exceptions\InvalidArticleBody;
use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;
use App\Rules\SafeLinksInText;
use App\Support\Links\ExternalLink;
use Illuminate\Support\Str;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Corps d'un article : document JSON de blocs, avec une liste fermée d'éléments. Jamais de
 * HTML stocké ni renvoyé : le serveur valide l'entrée puis reconstruit le document à partir des
 * seuls éléments autorisés (parse()), et c'est ce document normalisé qui est enregistré.
 *
 * Document :
 *   {"version": 1, "blocks": [bloc, …]}
 *
 * Blocs :
 *   {"type": "paragraph", "content": [segment, …]}
 *   {"type": "heading", "level": 2|3, "content": [segment sans lien, …]}
 *   {"type": "list", "style": "bullet"|"ordered", "items": [[segment, …], …]}   (sans imbrication)
 *   {"type": "quote", "content": [segment, …], "attribution": "…"|null}
 *   {"type": "image", "media": "<uuid d'un média article_image de l'auteur>", "alt": "…", "caption": "…"|null}
 *   {"type": "divider"}
 *
 * Segment de texte :
 *   {"text": "…", "marks": ["bold", "italic"], "link": "https://…"|null}
 *
 * Règles :
 * - toute clé, tout type, toute marque inconnus sont refusés (pas d'attributs, pas de style) ;
 * - le texte est brut (NoHtml), sans caractère invisible ni contrôle de direction, et les liens
 *   écrits dans le texte suivent les règles des liens externes (SafeLinksInText) ;
 * - un lien est une URL https vers un domaine public (ExternalLink : ni javascript:, ni data:,
 *   ni IP, ni raccourcisseur, ni domaine refusé) ;
 * - une image n'est jamais une URL : seulement l'uuid d'un média de l'auteur (vérifié au
 *   rattachement par MediaLibrary), donc aucune image externe ni pixel de suivi ;
 * - plafonds de config/blog.php (body) : blocs, caractères, segments, éléments de liste, images,
 *   liens.
 */
final class ArticleBody
{
    public const VERSION = 1;

    /** @var list<string> */
    public const BLOCK_TYPES = ['paragraph', 'heading', 'list', 'quote', 'image', 'divider'];

    /** @var list<string> */
    public const MARKS = ['bold', 'italic'];

    /** @var list<int> */
    public const HEADING_LEVELS = [2, 3];

    /** @var list<string> */
    public const LIST_STYLES = ['bullet', 'ordered'];

    private int $characters = 0;

    private int $images = 0;

    private int $links = 0;

    /** @var list<string> */
    private array $mediaUuids = [];

    private function __construct() {}

    /**
     * Document vide (brouillon sans corps).
     *
     * @return array{version: int, blocks: list<array<string, mixed>>}
     */
    public static function empty(): array
    {
        return ['version' => self::VERSION, 'blocks' => []];
    }

    /**
     * Valide l'entrée et renvoie le document normalisé.
     *
     * @return array{version: int, blocks: list<array<string, mixed>>}
     *
     * @throws InvalidArticleBody
     */
    public static function parse(mixed $input): array
    {
        return (new self)->document($input);
    }

    /**
     * Nombre de mots du texte lisible (segments, légendes, sources).
     *
     * @param  array<string, mixed>|null  $document
     */
    public static function wordCount(?array $document): int
    {
        return (int) preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’\-]*/u', self::plainText($document));
    }

    public static function readingMinutes(int $words): int
    {
        return max(1, (int) ceil($words / max(1, (int) config('blog.words_per_minute'))));
    }

    /**
     * Texte brut du document, un bloc par ligne (recherche, comptage des mots).
     *
     * @param  array<string, mixed>|null  $document
     */
    public static function plainText(?array $document): string
    {
        $lines = [];

        foreach (self::blocks($document) as $block) {
            $line = implode('', array_map(fn (array $segment): string => is_string($segment['text'] ?? null) ? $segment['text'] : '', self::segmentsOf($block)));

            foreach (['caption', 'attribution'] as $extra) {
                if (isset($block[$extra]) && is_string($block[$extra])) {
                    $line .= ' '.$block[$extra];
                }
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Liens du document : liens des segments, et liens écrits dans le texte (modération).
     *
     * @param  array<string, mixed>|null  $document
     * @return list<array{field: string, link: string, host: string|null}>
     */
    public static function links(?array $document): array
    {
        $links = [];

        foreach (self::blocks($document) as $index => $block) {
            $field = 'body.blocks.'.$index;

            foreach (self::segmentsOf($block) as $segment) {
                if (isset($segment['link']) && is_string($segment['link'])) {
                    $links[] = ['field' => $field, 'link' => $segment['link'], 'host' => ExternalLink::host($segment['link'])];
                }

                foreach (ExternalLink::extract((string) ($segment['text'] ?? '')) as $link) {
                    $links[] = ['field' => $field, 'link' => $link, 'host' => ExternalLink::textHost($link)];
                }
            }
        }

        return $links;
    }

    /**
     * Uuids des images du document, dans l'ordre.
     *
     * @param  array<string, mixed>|null  $document
     * @return list<string>
     */
    public static function imageUuids(?array $document): array
    {
        $uuids = [];

        foreach (self::blocks($document) as $block) {
            if (($block['type'] ?? null) === 'image' && is_string($block['media'] ?? null)) {
                $uuids[] = $block['media'];
            }
        }

        return array_values(array_unique($uuids));
    }

    /**
     * @param  array<string, mixed>|null  $document
     * @return list<array<string, mixed>>
     */
    public static function blocks(?array $document): array
    {
        $blocks = $document['blocks'] ?? [];

        return is_array($blocks) ? array_values(array_filter($blocks, 'is_array')) : [];
    }

    /**
     * Segments de texte d'un bloc normalisé (toutes les listes aplaties).
     *
     * @param  array<string, mixed>  $block
     * @return list<array<string, mixed>>
     */
    public static function segmentsOf(array $block): array
    {
        $segments = match ($block['type'] ?? null) {
            'list' => array_merge([], ...array_map(fn ($item): array => is_array($item) ? array_values($item) : [], array_values((array) ($block['items'] ?? [])))),
            'paragraph', 'heading', 'quote' => array_values((array) ($block['content'] ?? [])),
            default => [],
        };

        return array_values(array_filter($segments, 'is_array'));
    }

    /**
     * @return array{version: int, blocks: list<array<string, mixed>>}
     */
    private function document(mixed $input): array
    {
        if (! is_array($input) || array_is_list($input) && $input !== []) {
            throw new InvalidArticleBody('Le corps de l\'article doit être un document {"version": 1, "blocks": [...]}.');
        }

        $this->assertKeys($input, ['version', 'blocks'], ['version', 'blocks'], 'Le document');

        if ($input['version'] !== self::VERSION) {
            throw new InvalidArticleBody('La version du document doit être 1.');
        }

        $blocks = $input['blocks'];
        $max = (int) config('blog.body.max_blocks');

        if (! is_array($blocks) || ! array_is_list($blocks)) {
            throw new InvalidArticleBody('« blocks » doit être une liste de blocs.');
        }

        if (count($blocks) > $max) {
            throw new InvalidArticleBody("L'article contient trop de blocs ({$max} au maximum).");
        }

        $normalized = [];

        foreach ($blocks as $index => $block) {
            $normalized[] = $this->block($block, 'Bloc '.($index + 1));
        }

        $this->assertTotals();

        return ['version' => self::VERSION, 'blocks' => $normalized];
    }

    /**
     * @return array<string, mixed>
     */
    private function block(mixed $block, string $where): array
    {
        if (! is_array($block) || array_is_list($block)) {
            throw new InvalidArticleBody("{$where} : un bloc doit être un objet.");
        }

        $type = $block['type'] ?? null;

        if (! is_string($type) || ! in_array($type, self::BLOCK_TYPES, true)) {
            throw new InvalidArticleBody("{$where} : type de bloc non autorisé (".implode(', ', self::BLOCK_TYPES).').');
        }

        return match ($type) {
            'paragraph' => $this->paragraph($block, $where),
            'heading' => $this->heading($block, $where),
            'list' => $this->list($block, $where),
            'quote' => $this->quote($block, $where),
            'image' => $this->image($block, $where),
            'divider' => $this->divider($block, $where),
        };
    }

    /**
     * @param  array<array-key, mixed>  $block
     * @return array<string, mixed>
     */
    private function paragraph(array $block, string $where): array
    {
        $this->assertKeys($block, ['type', 'content'], ['type', 'content'], $where);

        return ['type' => 'paragraph', 'content' => $this->segments($block['content'], $where, allowLinks: true)];
    }

    /**
     * @param  array<array-key, mixed>  $block
     * @return array<string, mixed>
     */
    private function heading(array $block, string $where): array
    {
        $this->assertKeys($block, ['type', 'level', 'content'], ['type', 'level', 'content'], $where);

        if (! in_array($block['level'], self::HEADING_LEVELS, true)) {
            throw new InvalidArticleBody("{$where} : un intertitre est de niveau 2 ou 3 (le titre de l'article est le niveau 1).");
        }

        return ['type' => 'heading', 'level' => $block['level'], 'content' => $this->segments($block['content'], $where, allowLinks: false)];
    }

    /**
     * @param  array<array-key, mixed>  $block
     * @return array<string, mixed>
     */
    private function list(array $block, string $where): array
    {
        $this->assertKeys($block, ['type', 'style', 'items'], ['type', 'style', 'items'], $where);

        if (! in_array($block['style'], self::LIST_STYLES, true)) {
            throw new InvalidArticleBody("{$where} : une liste est à puces (bullet) ou numérotée (ordered).");
        }

        $items = $block['items'];
        $max = (int) config('blog.body.max_list_items');

        if (! is_array($items) || ! array_is_list($items) || $items === [] || count($items) > $max) {
            throw new InvalidArticleBody("{$where} : une liste contient de 1 à {$max} éléments.");
        }

        $normalized = [];

        foreach ($items as $index => $item) {
            // Un élément est une suite de segments : une liste imbriquée (objet "type") est refusée.
            $normalized[] = $this->segments($item, "{$where}, élément ".($index + 1), allowLinks: true);
        }

        return ['type' => 'list', 'style' => $block['style'], 'items' => $normalized];
    }

    /**
     * @param  array<array-key, mixed>  $block
     * @return array<string, mixed>
     */
    private function quote(array $block, string $where): array
    {
        $this->assertKeys($block, ['type', 'content', 'attribution'], ['type', 'content'], $where);

        return [
            'type' => 'quote',
            'content' => $this->segments($block['content'], $where, allowLinks: true),
            'attribution' => $this->optionalText($block['attribution'] ?? null, 200, "{$where}, source"),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $block
     * @return array<string, mixed>
     */
    private function image(array $block, string $where): array
    {
        $this->assertKeys($block, ['type', 'media', 'alt', 'caption'], ['type', 'media', 'alt'], $where);

        $media = $block['media'];

        if (! is_string($media) || ! Str::isUuid($media)) {
            throw new InvalidArticleBody("{$where} : une image est désignée par l'uuid d'une image envoyée (POST /media, usage article_image), jamais par une adresse.");
        }

        if (in_array($media, $this->mediaUuids, true)) {
            throw new InvalidArticleBody("{$where} : cette image figure déjà dans l'article.");
        }

        $this->mediaUuids[] = $media;
        $this->images++;

        $alt = $this->optionalText($block['alt'], 250, "{$where}, texte alternatif");

        if ($alt === null || mb_strlen(trim($alt)) < 3) {
            throw new InvalidArticleBody("{$where} : décrivez l'image en 3 caractères au moins (texte alternatif).");
        }

        return [
            'type' => 'image',
            'media' => strtolower($media),
            'alt' => $alt,
            'caption' => $this->optionalText($block['caption'] ?? null, 300, "{$where}, légende"),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $block
     * @return array<string, mixed>
     */
    private function divider(array $block, string $where): array
    {
        $this->assertKeys($block, ['type'], ['type'], $where);

        return ['type' => 'divider'];
    }

    /**
     * @return list<array{text: string, marks: list<string>, link: string|null}>
     */
    private function segments(mixed $segments, string $where, bool $allowLinks): array
    {
        $max = (int) config('blog.body.max_segments');

        if (! is_array($segments) || ! array_is_list($segments) || $segments === [] || count($segments) > $max) {
            throw new InvalidArticleBody("{$where} : le contenu est une liste de 1 à {$max} segments de texte.");
        }

        $normalized = [];

        foreach ($segments as $index => $segment) {
            $normalized[] = $this->segment($segment, "{$where}, segment ".($index + 1), $allowLinks);
        }

        if (trim(implode('', array_column($normalized, 'text'))) === '') {
            throw new InvalidArticleBody("{$where} : le texte est vide.");
        }

        return $normalized;
    }

    /**
     * @return array{text: string, marks: list<string>, link: string|null}
     */
    private function segment(mixed $segment, string $where, bool $allowLinks): array
    {
        if (! is_array($segment) || array_is_list($segment)) {
            throw new InvalidArticleBody("{$where} : un segment de texte doit être un objet {\"text\": …}.");
        }

        $this->assertKeys($segment, ['text', 'marks', 'link'], ['text'], $where);

        $text = $segment['text'];
        $maxLength = (int) config('blog.body.max_text_length');

        if (! is_string($text) || $text === '' || mb_strlen($text) > $maxLength) {
            throw new InvalidArticleBody("{$where} : le texte doit faire de 1 à {$maxLength} caractères.");
        }

        $this->assertText($text, $where);

        $marks = $segment['marks'] ?? [];

        if (! is_array($marks) || ! array_is_list($marks) || array_diff($marks, self::MARKS) !== [] || count($marks) !== count(array_unique($marks))) {
            throw new InvalidArticleBody("{$where} : mise en forme non autorisée (seulement bold et italic).");
        }

        $link = $segment['link'] ?? null;

        if ($link !== null) {
            if (! $allowLinks) {
                throw new InvalidArticleBody("{$where} : un intertitre ne contient pas de lien.");
            }

            if (! is_string($link) || strlen($link) > 2048) {
                throw new InvalidArticleBody("{$where} : le lien n'est pas une adresse web valide.");
            }

            $problem = ExternalLink::problem($link);

            if ($problem !== null) {
                throw new InvalidArticleBody("{$where} : {$problem}");
            }

            $this->links++;
        }

        sort($marks);

        return ['text' => $text, 'marks' => $marks, 'link' => $link];
    }

    private function optionalText(mixed $value, int $max, string $where): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || mb_strlen($value) > $max) {
            throw new InvalidArticleBody("{$where} : un texte de {$max} caractères au maximum est attendu.");
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $this->assertText($value, $where);

        return $value;
    }

    /**
     * Texte brut : ni HTML, ni caractère invisible, ni lien refusé. Compte les caractères.
     */
    private function assertText(string $text, string $where): void
    {
        foreach ([new NoHtml, new NoInvisibleCharacters, new SafeLinksInText] as $rule) {
            $error = null;

            $rule->validate('texte', $text, function (string $message) use (&$error): PotentiallyTranslatedString {
                $error ??= $message;

                return new PotentiallyTranslatedString($message, app('translator'));
            });

            if (is_string($error)) {
                throw new InvalidArticleBody($where.' : '.lcfirst(str_replace(':attribute', 'texte', $error)));
            }
        }

        $this->characters += mb_strlen($text);
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $allowed
     * @param  list<string>  $required
     */
    private function assertKeys(array $node, array $allowed, array $required, string $where): void
    {
        $unknown = array_diff(array_map('strval', array_keys($node)), $allowed);

        if ($unknown !== []) {
            throw new InvalidArticleBody("{$where} : propriété non autorisée (".mb_strimwidth(implode(', ', $unknown), 0, 60, '…').').');
        }

        foreach ($required as $key) {
            if (! array_key_exists($key, $node)) {
                throw new InvalidArticleBody("{$where} : « {$key} » est obligatoire.");
            }
        }
    }

    private function assertTotals(): void
    {
        $limits = [
            [$this->characters, (int) config('blog.body.max_characters'), 'L\'article dépasse %d caractères de texte.'],
            [$this->images, (int) config('blog.body.max_images'), 'L\'article contient plus de %d images.'],
            [$this->links, (int) config('blog.body.max_links'), 'L\'article contient plus de %d liens.'],
        ];

        foreach ($limits as [$count, $max, $message]) {
            if ($count > $max) {
                throw new InvalidArticleBody(sprintf($message, $max));
            }
        }
    }
}
