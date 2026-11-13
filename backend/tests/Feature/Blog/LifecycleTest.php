<?php

use App\Models\Media;
use App\Modules\Blog\Enums\ArticleStatus;
use App\Modules\Blog\Enums\RevisionStatus;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Models\ArticleQuotaEntry;
use App\Modules\Blog\Models\ArticleRetiredSlug;
use App\Modules\Blog\Models\ArticleRevision;
use App\Support\Media\MediaPurpose;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| Cycle de vie des données du Blog : suppression de compte et minimisation (blog:prune).
*/

beforeEach(fn () => Storage::fake('public'));

describe('suppression de compte', function (): void {
    it('supprime tous les articles d\'un auteur extérieur, publiés compris, avec leurs images', function (string $role): void {
        $author = articleAuthor($role);
        $published = articleOf($author, 'published');
        articleOf($author, 'pending');
        articleOf($author);
        $image = Media::factory()->create(['user_id' => $author->id, 'purpose' => MediaPurpose::ArticleImage, 'attached_at' => now()]);
        DB::table('article_media')->insert(['article_id' => $published->id, 'media_id' => $image->id]);
        $other = articleOf(articleAuthor(), 'published');

        $this->actingAs($author)->deleteJson('/api/v1/me', ['password' => 'password'])->assertNoContent();

        expect(Article::query()->pluck('id')->all())->toBe([$other->id])
            ->and(ArticleQuotaEntry::query()->where('user_id', $author->id)->exists())->toBeFalse()
            ->and(Media::query()->whereKey($image->id)->exists())->toBeFalse()
            ->and(ArticleRetiredSlug::query()->value('slug'))->toBe($published->slug);

        $this->getJson("/api/v1/articles/{$published->slug}")->assertStatus(410);
    })->with(['entrepreneur', 'prestataire']);

    it('détache et conserve les articles de l\'équipe', function (): void {
        $author = articleAuthor('redacteur');
        $article = articleOf($author, 'published');

        $this->actingAs($author)->deleteJson('/api/v1/me', ['password' => 'password'])->assertNoContent();

        expect($article->fresh()->user_id)->toBeNull()
            ->and($article->fresh()->status)->toBe(ArticleStatus::Published);

        $this->getJson("/api/v1/articles/{$article->slug}")
            ->assertOk()
            ->assertJsonPath('data.author.kind', 'editorial');
    });
});

describe('minimisation (blog:prune)', function (): void {
    it('supprime les données au-delà de leur durée de conservation, et garde le reste', function (): void {
        $author = articleAuthor();

        $this->travelTo(now()->subMonths(13));
        $abandoned = articleOf($author);
        $rejected = articleOf($author, 'draft', ['status' => ArticleStatus::Rejected, 'first_submitted_at' => now(), 'submission_count' => 1]);
        ArticleQuotaEntry::query()->forceCreate(['user_id' => $author->id, 'article_uuid' => $rejected->uuid, 'counted_at' => now()]);
        $unpublished = articleOf($author, 'published', ['status' => ArticleStatus::Unpublished]);
        $oldPublished = articleOf($author, 'published');
        $withdrawnDraft = articleOf($author, 'draft', ['first_submitted_at' => now(), 'submission_count' => 1]);
        $decided = ArticleRevision::query()->forceCreate([
            'uuid' => (string) Str::uuid(), 'article_id' => $oldPublished->id, 'user_id' => $author->id,
            'status' => RevisionStatus::Rejected, 'version' => 1, 'payload' => [], 'changed_fields' => ['title'],
            'submitted_at' => now(), 'decided_at' => now(),
        ]);
        (new ArticleRetiredSlug)->forceFill(['slug' => 'un-vieil-article', 'retired_at' => now()->subMonths(12)])->save();
        $this->travelBack();

        $recentDraft = articleOf($author);
        (new ArticleRetiredSlug)->forceFill(['slug' => 'un-article-recent', 'retired_at' => now()])->save();

        $this->artisan('blog:prune')->assertSuccessful();

        expect(Article::query()->pluck('id')->sort()->values()->all())->toBe([$oldPublished->id, $withdrawnDraft->id, $recentDraft->id])
            ->and(ArticleRevision::query()->whereKey($decided->id)->exists())->toBeFalse()
            ->and(ArticleRetiredSlug::query()->pluck('slug')->sort()->values()->all())->toBe([$unpublished->slug, 'un-article-recent'])
            ->and(ArticleQuotaEntry::query()->where('article_uuid', $rejected->uuid)->exists())->toBeTrue()
            ->and(Article::query()->whereKey($abandoned->id)->exists())->toBeFalse();
    });

    it('ne supprime pas une révision en attente', function (): void {
        $author = articleAuthor();
        $article = articleOf($author, 'published');
        $this->travelTo(now()->subYears(2));
        asAuthor($author)->patchJson("/api/v1/me/articles/{$article->uuid}", ['title' => 'Un titre révisé il y a longtemps'])->assertAccepted();
        $this->travelBack();

        $this->artisan('blog:prune')->assertSuccessful();

        expect(ArticleRevision::query()->sole()->status)->toBe(RevisionStatus::Pending);
    });
});
