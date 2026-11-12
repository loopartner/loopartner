<?php

namespace Database\Factories\Modules\Blog;

use App\Modules\Blog\Enums\ArticleStatus;
use App\Modules\Blog\Enums\AuthorKind;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Support\ArticleBody;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Article en brouillon d'un entrepreneur, complet (prêt à être soumis), en français.
 *
 * L'auteur (user_id) est à fournir : $factory->for($user). Les catégories se rattachent après
 * la création ($article->categories()->attach(...)).
 *
 * @extends Factory<Article>
 */
class ArticleFactory extends Factory
{
    protected $model = Article::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $body = self::body();

        return [
            'uuid' => (string) Str::uuid(),
            'author_kind' => AuthorKind::Entrepreneur,
            'status' => ArticleStatus::Draft,
            'version' => 1,
            'language' => 'fr',
            'title' => 'Bien choisir le statut juridique de son entreprise',
            'excerpt' => 'Micro-entreprise, SASU ou EURL : les critères qui comptent vraiment pour bien démarrer son activité.',
            'body' => $body,
            'author_name' => 'Camille, fondatrice',
            'word_count' => ArticleBody::wordCount($body),
            'reading_time_minutes' => ArticleBody::readingMinutes(ArticleBody::wordCount($body)),
        ];
    }

    /**
     * Corps valide d'environ 250 mots (au-dessus du minimum pour soumettre).
     *
     * @return array{version: int, blocks: list<array<string, mixed>>}
     */
    public static function body(int $paragraphs = 5): array
    {
        $sentence = 'Choisir un statut adapté à son projet permet de protéger son patrimoine, de maîtriser ses cotisations et de préparer la croissance de son entreprise sereinement. ';
        $blocks = [
            ['type' => 'heading', 'level' => 2, 'content' => [['text' => 'Les critères essentiels', 'marks' => [], 'link' => null]]],
        ];

        for ($i = 0; $i < $paragraphs; $i++) {
            $blocks[] = ['type' => 'paragraph', 'content' => [['text' => trim(str_repeat($sentence, 2)), 'marks' => [], 'link' => null]]];
        }

        return ['version' => ArticleBody::VERSION, 'blocks' => $blocks];
    }

    public function editorial(): static
    {
        return $this->state(fn (): array => ['author_kind' => AuthorKind::Editorial, 'author_name' => null]);
    }

    public function provider(): static
    {
        return $this->state(fn (): array => ['author_kind' => AuthorKind::Provider, 'author_name' => null]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => ArticleStatus::PendingReview,
            'submission_count' => 1,
            'first_submitted_at' => now()->subHour(),
            'submitted_at' => now()->subHour(),
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ArticleStatus::Published,
            'slug' => Str::slug((string) $attributes['title']).'-'.Str::lower(Str::random(5)),
            'submission_count' => 1,
            'first_submitted_at' => now()->subDays(3),
            'submitted_at' => now()->subDays(3),
            'published_at' => now()->subDays(2),
            'content_updated_at' => now()->subDays(2),
        ]);
    }
}
