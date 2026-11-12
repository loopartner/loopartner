<?php

use App\Models\Media;
use App\Models\User;
use App\Modules\Blog\Enums\ArticleStatus;
use App\Modules\Blog\Enums\RevisionStatus;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Models\ArticleQuotaEntry;
use App\Modules\Blog\Models\ArticleRetiredSlug;
use App\Modules\Blog\Models\ArticleRevision;
use App\Support\Media\MediaPurpose;
use Database\Factories\Modules\Blog\ArticleFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
| Articles de l'auteur connecté (/me/articles) : rédaction, soumission, quota gratuit,
| révisions d'un article publié, publication directe de l'équipe.
*/

beforeEach(fn () => Storage::fake('public'));

/**
 * Image envoyée par l'auteur, pas encore rattachée.
 */
function articleMedia(User $author, MediaPurpose $purpose = MediaPurpose::ArticleImage): Media
{
    return Media::factory()->create(['user_id' => $author->id, 'purpose' => $purpose, 'attached_at' => null]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function createArticleAs(User $author, array $overrides = []): Article
{
    $withName = $author->hasRole('entrepreneur');

    asAuthor($author)->postJson('/api/v1/me/articles', articlePayload($overrides, $withName))->assertCreated();

    return Article::query()->latest('id')->firstOrFail();
}

describe('création et modification', function (): void {
    it('crée un brouillon (201) avec le type d\'auteur fixé d\'après le rôle', function (string $role, string $kind): void {
        $author = articleAuthor($role);

        $response = asAuthor($author)
            ->postJson('/api/v1/me/articles', articlePayload([], $role === 'entrepreneur'))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.author_kind', $kind)
            ->assertJsonPath('data.slug', null)
            ->assertJsonPath('data.categories.0.slug', 'creation-entreprise')
            ->assertJsonPath('data.body.version', 1);

        expect($response->json('data.reading_time_minutes'))->toBeGreaterThanOrEqual(1)
            ->and($response->json('data.word_count'))->toBeGreaterThan(200);
        expectNoPrivateFields($response->json('data'));
    })->with([
        'entrepreneur' => ['entrepreneur', 'entrepreneur'],
        'prestataire' => ['prestataire', 'provider'],
        'rédacteur' => ['redacteur', 'editorial'],
        'admin' => ['admin', 'editorial'],
    ]);

    it('accepte un brouillon minimal (titre et langue)', function (): void {
        asAuthor(articleAuthor())
            ->postJson('/api/v1/me/articles', ['title' => 'Un premier brouillon d\'article', 'language' => 'en'])
            ->assertCreated()
            ->assertJsonPath('data.excerpt', null)
            ->assertJsonPath('data.body.blocks', []);
    });

    it('refuse une langue hors de la liste configurée, puis l\'accepte une fois ajoutée', function (): void {
        $author = articleAuthor();

        asAuthor($author)->postJson('/api/v1/me/articles', articlePayload(['language' => 'de']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['language']);

        config(['blog.languages' => ['fr', 'en', 'de']]);

        asAuthor($author)->postJson('/api/v1/me/articles', articlePayload(['language' => 'de']))->assertCreated();
    });

    it('réserve le nom d\'auteur aux entrepreneurs', function (string $role): void {
        asAuthor(articleAuthor($role))
            ->postJson('/api/v1/me/articles', articlePayload(['author_name' => 'Un nom']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['author_name']);
    })->with(['prestataire', 'redacteur']);

    it('refuse les champs fixés par la plateforme (422)', function (string $field, mixed $value): void {
        asAuthor(articleAuthor())
            ->postJson('/api/v1/me/articles', articlePayload([$field => $value]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    })->with([
        ['status', 'published'],
        ['slug', 'mon-article'],
        ['version', 9],
        ['user_id', 1],
        ['author_kind', 'editorial'],
        ['published_at', '2026-01-01T00:00:00Z'],
        ['submission_count', 0],
        ['cover_media_id', 1],
        ['premium', true],
    ]);

    it('plafonne le nombre de brouillons (409)', function (): void {
        config(['blog.max_drafts_per_author' => 2]);
        $author = articleAuthor();
        articleOf($author);
        articleOf($author);

        asAuthor($author)->postJson('/api/v1/me/articles', articlePayload())->assertConflict();
    });

    it('modifie un brouillon directement et augmente sa version', function (): void {
        $author = articleAuthor();
        $article = articleOf($author);

        asAuthor($author)
            ->patchJson("/api/v1/me/articles/{$article->uuid}", ['title' => 'Un tout nouveau titre pour cet article'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Un tout nouveau titre pour cet article')
            ->assertJsonPath('data.version', 2);
    });

    it('refuse de modifier un article en attente (409)', function (): void {
        $author = articleAuthor();
        $article = articleOf($author, 'pending');

        asAuthor($author)->patchJson("/api/v1/me/articles/{$article->uuid}", ['title' => 'Un tout nouveau titre pour cet article'])->assertConflict();
    });

    it('rattache la couverture et les images du corps, et supprime celles retirées', function (): void {
        $author = articleAuthor();
        $cover = articleMedia($author, MediaPurpose::ArticleCover);
        $image = articleMedia($author);
        $body = ArticleFactory::body();
        $body['blocks'][] = ['type' => 'image', 'media' => $image->uuid, 'alt' => 'Un schéma explicatif'];

        $article = createArticleAs($author, ['cover' => $cover->uuid, 'body' => $body]);

        expect($cover->fresh()->isAttached())->toBeTrue()
            ->and($image->fresh()->isAttached())->toBeTrue()
            ->and(DB::table('article_media')->where('article_id', $article->id)->count())->toBe(1);

        asAuthor($author)
            ->getJson("/api/v1/me/articles/{$article->uuid}")
            ->assertOk()
            ->assertJsonPath('data.cover.uuid', $cover->uuid)
            ->assertJsonPath('data.body.blocks.6.url', $image->url());

        asAuthor($author)->patchJson("/api/v1/me/articles/{$article->uuid}", ['body' => ArticleFactory::body(), 'cover' => null])->assertOk();

        expect(Media::query()->whereKey([$cover->id, $image->id])->count())->toBe(0);
    });

    it('refuse l\'image d\'un autre utilisateur ou d\'un autre usage (422)', function (string $case): void {
        $author = articleAuthor();
        $media = $case === 'autre utilisateur' ? articleMedia(articleAuthor()) : articleMedia($author, MediaPurpose::Avatar);
        $body = ArticleFactory::body();
        $body['blocks'][] = ['type' => 'image', 'media' => $media->uuid, 'alt' => 'Une photo volée'];

        asAuthor($author)->postJson('/api/v1/me/articles', articlePayload(['body' => $body]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body']);

        expect(Article::query()->count())->toBe(0)
            ->and($media->fresh()->isAttached())->toBeFalse();
    })->with(['autre utilisateur', 'autre usage']);

    it('supprime un article (204), ses images, et met son slug en retraite s\'il a été publié', function (): void {
        $author = articleAuthor();
        $article = articleOf($author, 'published');
        $image = articleMedia($author);
        DB::table('article_media')->insert(['article_id' => $article->id, 'media_id' => $image->id]);

        asAuthor($author)->deleteJson("/api/v1/me/articles/{$article->uuid}")->assertNoContent();

        expect(Article::query()->count())->toBe(0)
            ->and(Media::query()->whereKey($image->id)->exists())->toBeFalse()
            ->and(ArticleRetiredSlug::query()->value('slug'))->toBe($article->slug)
            ->and(ArticleQuotaEntry::query()->where('article_uuid', $article->uuid)->exists())->toBeTrue();
    });
});

describe('soumission et quota', function (): void {
    it('soumet un article complet (202) et le compte dans le quota', function (): void {
        $author = articleAuthor();
        $article = articleOf($author);

        asAuthor($author)
            ->postJson("/api/v1/me/articles/{$article->uuid}/submit")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.submission_count', 1);

        expect(ArticleQuotaEntry::query()->where('user_id', $author->id)->count())->toBe(1)
            ->and(securityLog('blog.article_submitted')[0]->context)->toMatchArray(['article' => $article->uuid]);
    });

    it('refuse un article incomplet (422)', function (array $attributes, string $field): void {
        $author = articleAuthor();
        $article = articleOf($author, 'draft', $attributes);

        asAuthor($author)
            ->postJson("/api/v1/me/articles/{$article->uuid}/submit")
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    })->with(fn (): array => [
        'sans chapô' => [['excerpt' => null], 'excerpt'],
        'corps trop court' => [['body' => ArticleFactory::body(1), 'word_count' => 50], 'body'],
        'sans nom d\'auteur' => [['author_name' => null], 'author_name'],
    ]);

    it('exige au moins une catégorie pour soumettre', function (): void {
        $author = articleAuthor();
        $article = articleOf($author);
        $article->categories()->detach();

        asAuthor($author)->postJson("/api/v1/me/articles/{$article->uuid}/submit")->assertJsonValidationErrors(['categories']);
    });

    it('refuse au-delà du quota gratuit avec le code premium_required (403)', function (string $role): void {
        $author = articleAuthor($role);
        articleOf($author, 'published');
        articleOf($author, 'pending');
        $third = articleOf($author);

        asAuthor($author)
            ->postJson("/api/v1/me/articles/{$third->uuid}/submit")
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'Vous avez utilisé vos 2 articles gratuits. Passez premium pour soumettre de nouveaux articles.',
                'code' => 'premium_required',
                'quota' => ['limit' => 2, 'used' => 2, 'remaining' => 0],
            ]);

        expect($third->fresh()->status)->toBe(ArticleStatus::Draft)
            ->and(securityLog('blog.quota_reached'))->toHaveCount(1);
    })->with(['entrepreneur', 'prestataire']);

    it('ne libère pas de place quand un article est supprimé', function (): void {
        $author = articleAuthor();
        articleOf($author, 'published')->delete();
        articleOf($author, 'pending');
        $third = articleOf($author);

        asAuthor($author)->postJson("/api/v1/me/articles/{$third->uuid}/submit")->assertForbidden();
    });

    it('resoumet un article refusé sans consommer de nouvelle place, au plus 5 fois', function (): void {
        config(['blog.free_articles_quota' => 1]);
        $author = articleAuthor();
        $article = articleOf($author, 'draft', ['status' => ArticleStatus::Rejected, 'submission_count' => 1, 'first_submitted_at' => now()]);
        ArticleQuotaEntry::query()->forceCreate(['user_id' => $author->id, 'article_uuid' => $article->uuid, 'counted_at' => now()]);

        asAuthor($author)->postJson("/api/v1/me/articles/{$article->uuid}/submit")->assertAccepted();

        expect(ArticleQuotaEntry::query()->count())->toBe(1);

        $article->forceFill(['status' => ArticleStatus::Rejected, 'submission_count' => 6])->save();

        asAuthor($author)->postJson("/api/v1/me/articles/{$article->uuid}/submit")->assertConflict();
    });

    it('n\'applique pas le quota à un auteur premium, mais compte ses soumissions', function (): void {
        $author = articleAuthor('prestataire');
        makePremium($author);
        articleOf($author, 'published');
        articleOf($author, 'published');
        $third = articleOf($author);

        asAuthor($author)->postJson("/api/v1/me/articles/{$third->uuid}/submit")->assertAccepted();

        expect(ArticleQuotaEntry::query()->where('user_id', $author->id)->count())->toBe(3);
    });

    it('rétablit le quota à la fin du premium, sans dépublier les articles', function (): void {
        $author = articleAuthor();
        makePremium($author, ['starts_at' => now()->subMonths(2), 'ends_at' => now()->subDay()]);
        $published = [articleOf($author, 'published'), articleOf($author, 'published'), articleOf($author, 'published')];
        $draft = articleOf($author);

        asAuthor($author)->postJson("/api/v1/me/articles/{$draft->uuid}/submit")
            ->assertForbidden()
            ->assertJsonPath('code', 'premium_required')
            ->assertJsonPath('quota.used', 3);

        foreach ($published as $article) {
            expect($article->fresh()->status)->toBe(ArticleStatus::Published);
        }
    });

    it('plafonne les articles en attente, même pour un auteur premium (409)', function (): void {
        $author = articleAuthor();
        makePremium($author);
        articleOf($author, 'pending');
        articleOf($author, 'pending');
        articleOf($author, 'pending');
        $fourth = articleOf($author);

        asAuthor($author)->postJson("/api/v1/me/articles/{$fourth->uuid}/submit")->assertConflict();

        expect(securityLog('blog.pending_limit_reached'))->toHaveCount(1);
    });

    it('applique un quota sur une période glissante quand il est configuré', function (): void {
        config(['blog.free_quota_period_months' => 12]);
        $author = articleAuthor();
        $this->travelTo(now()->subMonths(13));
        articleOf($author, 'published');
        articleOf($author, 'published');
        $this->travelBack();

        asAuthor($author)->postJson('/api/v1/me/articles/'.articleOf($author)->uuid.'/submit')->assertAccepted();
    });

    it('expose le quota dans GET /me/articles/quota, GET /me/articles et GET /me', function (): void {
        $author = articleAuthor();
        articleOf($author, 'pending');

        $expected = ['premium' => false, 'limit' => 2, 'used' => 1, 'remaining' => 1];

        asAuthor($author)->getJson('/api/v1/me/articles/quota')->assertOk()->assertExactJson(['data' => $expected]);
        asAuthor($author)->getJson('/api/v1/me/articles')->assertOk()->assertJsonPath('meta.quota', $expected);
        asAuthor($author)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.article_quota', $expected);
    });

    it('donne un quota null aux rôles sans quota dans GET /me', function (string $role): void {
        $user = articleAuthor($role === 'particulier' ? 'entrepreneur' : $role);

        if ($role === 'particulier') {
            $user->syncRoles(['particulier']);
        }

        asAuthor($user)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.article_quota', null);
    })->with(['particulier', 'redacteur', 'admin']);

    it('retire une soumission (le brouillon redevient modifiable, la place reste comptée)', function (): void {
        $author = articleAuthor();
        $article = articleOf($author, 'pending');

        asAuthor($author)->postJson("/api/v1/me/articles/{$article->uuid}/withdraw")->assertOk()->assertJsonPath('data.status', 'draft');
        asAuthor($author)->postJson("/api/v1/me/articles/{$article->uuid}/withdraw")->assertConflict();

        expect(ArticleQuotaEntry::query()->count())->toBe(1);
    });

    it('refuse de soumettre un article déjà en attente ou publié (409)', function (string $state): void {
        $author = articleAuthor();
        $article = articleOf($author, $state);

        asAuthor($author)->postJson("/api/v1/me/articles/{$article->uuid}/submit")->assertConflict();
    })->with(['pending', 'published']);

    it('refuse la soumission d\'un prestataire dont le profil n\'est plus publié (403)', function (): void {
        $author = articleAuthor('prestataire');
        $article = articleOf($author);
        DB::table('provider_profiles')->where('user_id', $author->id)->update(['status' => 'draft']);

        asAuthor($author)->postJson("/api/v1/me/articles/{$article->uuid}/submit")->assertForbidden();
    });
});

describe('équipe : publication directe', function (): void {
    it('publie directement un article de la rédaction (200), avec slug et trace d\'audit', function (string $role): void {
        $author = articleAuthor($role);
        $article = articleOf($author);

        asAuthor($author)
            ->postJson("/api/v1/me/articles/{$article->uuid}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.slug', 'bien-choisir-le-statut-juridique-de-son-entreprise');

        expect(ArticleQuotaEntry::query()->count())->toBe(0)
            ->and(DB::table('admin_audit_logs')->where('action', 'blog.article.published_directly')->count())->toBe(1);
    })->with(['redacteur', 'admin']);

    it('exige la 2FA de session pour publier ou modifier un article publié de la rédaction (403)', function (): void {
        $author = articleAuthor('redacteur');
        $draft = articleOf($author);
        $published = articleOf($author, 'published');

        fromFrontend()->actingAs($author)->postJson("/api/v1/me/articles/{$draft->uuid}/submit")->assertForbidden();
        fromFrontend()->actingAs($author)->patchJson("/api/v1/me/articles/{$published->uuid}", ['title' => 'Un titre modifié par la rédaction'])->assertForbidden();

        expect($draft->fresh()->status)->toBe(ArticleStatus::Draft);
    });

    it('modifie directement un article publié de la rédaction (200), sans révision', function (): void {
        $author = articleAuthor('redacteur');
        $article = articleOf($author, 'published');

        asAuthor($author)
            ->patchJson("/api/v1/me/articles/{$article->uuid}", ['title' => 'Un titre modifié par la rédaction'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Un titre modifié par la rédaction')
            ->assertJsonPath('data.slug', $article->slug);

        expect(ArticleRevision::query()->count())->toBe(0)
            ->and(securityLog('blog.updated_directly'))->toHaveCount(1);
    });

    it('donne des slugs uniques et ne reprend pas un slug retiré', function (): void {
        $author = articleAuthor('redacteur');
        ArticleRetiredSlug::query()->forceCreate(['slug' => 'bien-choisir-le-statut-juridique-de-son-entreprise', 'retired_at' => now()]);

        foreach ([2, 3] as $suffix) {
            asAuthor($author)
                ->postJson('/api/v1/me/articles/'.articleOf($author)->uuid.'/submit')
                ->assertJsonPath('data.slug', "bien-choisir-le-statut-juridique-de-son-entreprise-{$suffix}");
        }
    });
});

describe('révisions d\'un article publié', function (): void {
    it('crée une révision en attente (202) sans changer la version publiée', function (): void {
        $author = articleAuthor();
        $article = articleOf($author, 'published');

        asAuthor($author)
            ->patchJson("/api/v1/me/articles/{$article->uuid}", ['title' => 'Un titre révisé pour cet article'])
            ->assertAccepted()
            ->assertJsonPath('data.title', $article->title)
            ->assertJsonPath('data.pending_revision.changed_fields', ['title'])
            ->assertJsonPath('data.pending_revision.content.title', 'Un titre révisé pour cet article');

        expect($article->fresh()->title)->toBe($article->title)
            ->and(securityLog('blog.revision_submitted')[0]->context['changed_fields'])->toBe(['title']);
    });

    it('cumule les modifications dans une seule révision en attente', function (): void {
        $author = articleAuthor();
        $article = articleOf($author, 'published');

        asAuthor($author)->patchJson("/api/v1/me/articles/{$article->uuid}", ['title' => 'Un titre révisé pour cet article'])->assertAccepted();
        asAuthor($author)->patchJson("/api/v1/me/articles/{$article->uuid}", ['seo_title' => 'Statut juridique : le guide'])->assertAccepted();

        $revision = ArticleRevision::query()->sole();

        expect($revision->version)->toBe(2)
            ->and($revision->changed_fields)->toBe(['title', 'seo_title']);
    });

    it('retire la révision quand la modification ne change plus rien', function (): void {
        $author = articleAuthor();
        $article = articleOf($author, 'published');

        asAuthor($author)->patchJson("/api/v1/me/articles/{$article->uuid}", ['title' => 'Un titre révisé pour cet article'])->assertAccepted();
        asAuthor($author)->patchJson("/api/v1/me/articles/{$article->uuid}", ['title' => $article->title])->assertOk();

        expect(ArticleRevision::query()->sole()->status)->toBe(RevisionStatus::Superseded);
    });

    it('rattache les nouvelles images à la révision, pas à l\'article', function (): void {
        $author = articleAuthor();
        $article = articleOf($author, 'published');
        $image = articleMedia($author);
        $body = ArticleFactory::body();
        $body['blocks'][] = ['type' => 'image', 'media' => $image->uuid, 'alt' => 'Un nouveau schéma'];

        asAuthor($author)->patchJson("/api/v1/me/articles/{$article->uuid}", ['body' => $body])->assertAccepted();

        $revision = ArticleRevision::query()->sole();

        expect(DB::table('article_media')->where('article_revision_id', $revision->id)->count())->toBe(1)
            ->and(DB::table('article_media')->where('article_id', $article->id)->count())->toBe(0);

        asAuthor($author)->deleteJson("/api/v1/me/articles/{$article->uuid}/revision")->assertNoContent();

        expect($revision->fresh()->status)->toBe(RevisionStatus::Withdrawn)
            ->and(Media::query()->whereKey($image->id)->exists())->toBeFalse();
    });

    it('répond 404 au retrait d\'une révision absente', function (): void {
        $author = articleAuthor();
        $article = articleOf($author, 'published');

        asAuthor($author)->deleteJson("/api/v1/me/articles/{$article->uuid}/revision")->assertNotFound();
    });

    it('limite le nombre de révisions (429)', function (): void {
        config(['security.rate_limits.article_revisions.per_hour' => 2]);
        $author = articleAuthor();
        $article = articleOf($author, 'published');

        foreach (['Un titre révisé numéro un', 'Un titre révisé numéro deux'] as $title) {
            asAuthor($author)->patchJson("/api/v1/me/articles/{$article->uuid}", ['title' => $title])->assertAccepted();
        }

        asAuthor($author)->patchJson("/api/v1/me/articles/{$article->uuid}", ['title' => 'Un titre révisé numéro trois'])->assertTooManyRequests();
    });
});
