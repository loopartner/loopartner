<?php

use App\Models\AdminAuditLog;
use App\Models\Media;
use App\Models\User;
use App\Modules\Blog\Actions\NotifyModerators;
use App\Modules\Blog\Enums\ArticleNotice;
use App\Modules\Blog\Enums\ArticleStatus;
use App\Modules\Blog\Enums\RevisionStatus;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Models\ArticleRevision;
use App\Modules\Blog\Notifications\ArticleNotification;
use App\Modules\Blog\Notifications\ModerationDigestNotification;
use App\Support\Media\MediaPurpose;
use Database\Factories\Modules\Blog\ArticleFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| Modération du Blog : rédacteurs et admins avec la 2FA de session ; jamais sur son propre
| article ; contrôle de version ; refus et dépublication avec motif ; journal d'audit ;
| récapitulatif regroupé.
*/

beforeEach(function (): void {
    Storage::fake('public');
    Notification::fake();
});

function blogModerator(string $role = 'redacteur'): User
{
    return articleAuthor($role);
}

/**
 * Révision en attente d'un article publié, créée par l'API.
 */
function pendingArticleRevision(Article $article, array $changes = ['title' => 'Un titre révisé pour cet article']): ArticleRevision
{
    /** @var User $author */
    $author = $article->user;

    asAuthor($author)->patchJson("/api/v1/me/articles/{$article->uuid}", $changes)->assertAccepted();

    return ArticleRevision::query()->where('article_id', $article->id)->where('status', 'pending')->sole();
}

describe('file et lecture', function (): void {
    it('liste les articles et révisions en attente, du plus ancien au plus récent', function (): void {
        $moderator = blogModerator();
        $old = articleOf(articleAuthor(), 'pending', ['submitted_at' => now()->subDays(2)]);
        $revision = pendingArticleRevision(articleOf(articleAuthor('prestataire'), 'published'));
        articleOf(articleAuthor());

        asModerator($moderator)
            ->getJson('/api/v1/admin/blog/queue')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.uuid', $old->uuid)
            ->assertJsonPath('data.0.own', false)
            ->assertJsonPath('data.1.kind', 'revision')
            ->assertJsonPath('data.1.uuid', $revision->uuid)
            ->assertJsonPath('meta.counts', ['article' => 1, 'revision' => 1]);
    });

    it('montre le contenu complet, les domaines signalés et l\'historique de l\'auteur', function (): void {
        $author = articleAuthor();
        articleOf($author, 'published', ['body' => ['version' => 1, 'blocks' => [['type' => 'paragraph', 'content' => [['text' => 'Connu', 'marks' => [], 'link' => 'https://connu.fr']]]]]]);
        $body = ArticleFactory::body();
        $body['blocks'][] = ['type' => 'paragraph', 'content' => [
            ['text' => 'Site', 'marks' => [], 'link' => 'https://connu.fr/page'],
            ['text' => ' et ', 'marks' => [], 'link' => null],
            ['text' => 'autre', 'marks' => [], 'link' => 'https://xn--pple-43d.com'],
        ]];
        $article = articleOf($author, 'pending', ['body' => $body]);

        $response = asModerator(blogModerator())
            ->getJson("/api/v1/admin/articles/{$article->uuid}")
            ->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.content.title', $article->title)
            ->assertJsonPath('data.author.kind', 'entrepreneur')
            ->assertJsonPath('data.author_history.articles.published', 1)
            ->assertJsonPath('data.author_history.account.premium', false);

        $domains = collect($response->json('data.domains'))->keyBy('host');

        expect($domains['connu.fr']['previously_approved'])->toBeTrue()
            ->and($domains['xn--pple-43d.com'])->toMatchArray(['punycode' => true, 'previously_approved' => false]);
        expectNoPrivateFields($response->json('data'));
    });

    it('ne montre pas un brouillon (404)', function (): void {
        $article = articleOf(articleAuthor());

        asModerator(blogModerator())->getJson("/api/v1/admin/articles/{$article->uuid}")->assertNotFound();
    });
});

describe('décisions sur un article', function (): void {
    it('valide un article (publication, slug, audit, email)', function (string $role): void {
        $moderator = blogModerator($role);
        $author = articleAuthor();
        $article = articleOf($author, 'pending');

        asModerator($moderator)
            ->postJson("/api/v1/admin/articles/{$article->uuid}/approve", ['version' => 1])
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.decisions.0.action', 'blog.article.approved');

        $article->refresh();

        expect($article->slug)->toBe('bien-choisir-le-statut-juridique-de-son-entreprise')
            ->and($article->published_at)->not->toBeNull()
            ->and(AdminAuditLog::query()->sole()->admin_id)->toBe($moderator->id)
            ->and(securityLog('blog.article_approved')[0]->context)->toMatchArray(['article' => $article->uuid]);

        Notification::assertSentTo($author, ArticleNotification::class, fn (ArticleNotification $n): bool => $n->notice === ArticleNotice::Approved);
    })->with(['redacteur', 'admin']);

    it('refuse un article avec motif : audit et email, mais rien dans le canal security', function (): void {
        $author = articleAuthor();
        $article = articleOf($author, 'pending');
        $reason = 'Le texte contient surtout de la publicité pour un service.';

        asModerator(blogModerator())
            ->postJson("/api/v1/admin/articles/{$article->uuid}/reject", ['version' => 1, 'reason' => $reason])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', $reason);

        expect(AdminAuditLog::query()->sole()->reason)->toBe($reason)
            ->and(json_encode(securityLog('blog.article_rejected')[0]->context))->not->toContain('publicité');

        Notification::assertSentTo($author, ArticleNotification::class, fn (ArticleNotification $n): bool => $n->notice === ArticleNotice::Rejected
            && str_contains(implode(' ', $n->toMail($author)->introLines), $reason));
    });

    it('refuse une décision sur une version périmée (409)', function (): void {
        $article = articleOf(articleAuthor(), 'pending', ['version' => 3]);

        asModerator(blogModerator())->postJson("/api/v1/admin/articles/{$article->uuid}/approve", ['version' => 2])->assertConflict();

        expect($article->fresh()->status)->toBe(ArticleStatus::PendingReview);
    });

    it('refuse de valider un article qui n\'est plus en attente (409)', function (): void {
        $article = articleOf(articleAuthor(), 'published');

        asModerator(blogModerator())->postJson("/api/v1/admin/articles/{$article->uuid}/approve", ['version' => 1])->assertConflict();
    });

    it('refuse de valider l\'article d\'un prestataire dont le profil n\'est plus publié (409)', function (): void {
        $author = articleAuthor('prestataire');
        $article = articleOf($author, 'pending');
        DB::table('provider_profiles')->where('user_id', $author->id)->update(['status' => 'draft']);

        asModerator(blogModerator())->postJson("/api/v1/admin/articles/{$article->uuid}/approve", ['version' => 1])->assertConflict();
    });

    it('valide les données de la décision (422)', function (string $action, array $payload, string $field): void {
        $article = articleOf(articleAuthor(), 'pending');

        asModerator(blogModerator())
            ->postJson("/api/v1/admin/articles/{$article->uuid}/{$action}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    })->with([
        'version absente' => ['approve', [], 'version'],
        'motif à la validation' => ['approve', ['version' => 1, 'reason' => 'Un motif inutile ici.'], 'reason'],
        'motif absent' => ['reject', ['version' => 1], 'reason'],
        'motif trop court' => ['reject', ['version' => 1, 'reason' => 'Non.'], 'reason'],
        'motif en HTML' => ['reject', ['version' => 1, 'reason' => '<script>alert(1)</script> refusé'], 'reason'],
        'version à la dépublication' => ['unpublish', ['version' => 1, 'reason' => 'Contenu promotionnel.'], 'version'],
    ]);

    it('dépublie un article avec motif : 404 public, révision close, slug conservé', function (): void {
        $author = articleAuthor();
        $article = articleOf($author, 'published');
        $revision = pendingArticleRevision($article);

        asModerator(blogModerator())
            ->postJson("/api/v1/admin/articles/{$article->uuid}/unpublish", ['reason' => 'Contenu trompeur signalé par plusieurs lecteurs.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'unpublished')
            ->assertJsonPath('data.slug', $article->slug);

        expect($revision->fresh()->status)->toBe(RevisionStatus::Superseded);
        Notification::assertSentTo($author, ArticleNotification::class, fn (ArticleNotification $n): bool => $n->notice === ArticleNotice::Unpublished);
    });
});

describe('personne ne modère son propre article', function (): void {
    it('refuse à un membre de l\'équipe toute décision sur son propre article (403)', function (string $action, array $payload): void {
        $moderator = blogModerator();
        $article = articleOf(articleAuthor(), 'pending');
        // L'auteur est devenu rédacteur après avoir soumis son article.
        $article->forceFill(['user_id' => $moderator->id])->save();

        asModerator($moderator)->postJson("/api/v1/admin/articles/{$article->uuid}/{$action}", $payload)->assertForbidden();

        expect($article->fresh()->status)->toBe(ArticleStatus::PendingReview)
            ->and(securityLog('blog.self_moderation_denied'))->toHaveCount(1)
            ->and(AdminAuditLog::query()->count())->toBe(0);
    })->with([
        'validation' => ['approve', ['version' => 1]],
        'refus' => ['reject', ['version' => 1, 'reason' => 'Je refuse mon propre article.']],
    ]);

    it('refuse de valider sa propre révision (403)', function (): void {
        $moderator = blogModerator();
        $revision = pendingArticleRevision(articleOf(articleAuthor(), 'published'));
        $revision->article->forceFill(['user_id' => $moderator->id])->save();

        asModerator($moderator)->postJson("/api/v1/admin/article-revisions/{$revision->uuid}/approve", ['version' => 1])->assertForbidden();
    });

    it('signale ses propres éléments dans la file (own)', function (): void {
        $moderator = blogModerator();
        $article = articleOf(articleAuthor(), 'pending');
        $article->forceFill(['user_id' => $moderator->id])->save();

        asModerator($moderator)->getJson('/api/v1/admin/blog/queue')->assertJsonPath('data.0.own', true);
    });

    it('réserve aux admins la dépublication d\'un article de la rédaction', function (): void {
        $article = articleOf(articleAuthor('redacteur'), 'published');

        asModerator(blogModerator('redacteur'))
            ->postJson("/api/v1/admin/articles/{$article->uuid}/unpublish", ['reason' => 'Article obsolète à retirer.'])
            ->assertForbidden();

        asModerator(blogModerator('admin'))
            ->postJson("/api/v1/admin/articles/{$article->uuid}/unpublish", ['reason' => 'Article obsolète à retirer.'])
            ->assertOk();
    });
});

describe('révisions', function (): void {
    it('valide une révision : nouvelle version publique, images et couverture appliquées', function (): void {
        $author = articleAuthor();
        $article = articleOf($author, 'published');
        $oldImage = Media::factory()->create(['user_id' => $author->id, 'purpose' => MediaPurpose::ArticleImage, 'attached_at' => now()]);
        DB::table('article_media')->insert(['article_id' => $article->id, 'media_id' => $oldImage->id]);
        $newImage = Media::factory()->create(['user_id' => $author->id, 'purpose' => MediaPurpose::ArticleImage, 'attached_at' => null]);
        $cover = Media::factory()->create(['user_id' => $author->id, 'purpose' => MediaPurpose::ArticleCover, 'attached_at' => null]);
        $body = ArticleFactory::body();
        $body['blocks'][] = ['type' => 'image', 'media' => $newImage->uuid, 'alt' => 'Le nouveau schéma'];

        $revision = pendingArticleRevision($article, ['title' => 'Un titre révisé pour cet article', 'body' => $body, 'cover' => $cover->uuid]);

        asModerator(blogModerator())
            ->postJson("/api/v1/admin/article-revisions/{$revision->uuid}/approve", ['version' => 1])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $article->refresh();

        expect($article->title)->toBe('Un titre révisé pour cet article')
            ->and($article->slug)->not->toBeNull()
            ->and($article->version)->toBe(2)
            ->and($article->cover_media_id)->toBe($cover->id)
            ->and(DB::table('article_media')->where('article_id', $article->id)->pluck('media_id')->all())->toBe([$newImage->id])
            ->and(Media::query()->whereKey($oldImage->id)->exists())->toBeFalse();

        Notification::assertSentTo($author, ArticleNotification::class, fn (ArticleNotification $n): bool => $n->notice === ArticleNotice::RevisionApproved);
    });

    it('refuse une révision avec motif : la version publiée reste, les images proposées disparaissent', function (): void {
        $author = articleAuthor();
        $article = articleOf($author, 'published');
        $image = Media::factory()->create(['user_id' => $author->id, 'purpose' => MediaPurpose::ArticleImage, 'attached_at' => null]);
        $body = ArticleFactory::body();
        $body['blocks'][] = ['type' => 'image', 'media' => $image->uuid, 'alt' => 'Une image refusée'];
        $revision = pendingArticleRevision($article, ['body' => $body]);

        asModerator(blogModerator())
            ->postJson("/api/v1/admin/article-revisions/{$revision->uuid}/reject", ['version' => 1, 'reason' => 'Le lien ajouté pointe vers un site commercial.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        expect($article->fresh()->body)->toBe($article->body)
            ->and(Media::query()->whereKey($image->id)->exists())->toBeFalse();

        asAuthor($author)->getJson("/api/v1/me/articles/{$article->uuid}")
            ->assertJsonPath('data.last_revision_decision.status', 'rejected')
            ->assertJsonPath('data.last_revision_decision.rejection_reason', 'Le lien ajouté pointe vers un site commercial.');
    });

    it('refuse une révision remplacée depuis la lecture (409)', function (): void {
        $article = articleOf(articleAuthor(), 'published');
        $revision = pendingArticleRevision($article);
        pendingArticleRevision($article, ['seo_title' => 'Statut juridique : le guide']);

        asModerator(blogModerator())->postJson("/api/v1/admin/article-revisions/{$revision->uuid}/approve", ['version' => 1])->assertConflict();
    });

    it('montre la version proposée et la version publiée côte à côte', function (): void {
        $article = articleOf(articleAuthor(), 'published');
        $revision = pendingArticleRevision($article);

        asModerator(blogModerator())
            ->getJson("/api/v1/admin/article-revisions/{$revision->uuid}")
            ->assertOk()
            ->assertJsonPath('data.proposed.title', 'Un titre révisé pour cet article')
            ->assertJsonPath('data.current.title', $article->title)
            ->assertJsonPath('data.changed_fields', ['title']);
    });
});

describe('accès et limites', function (): void {
    it('cache la modération aux autres rôles, même avec la 2FA (404)', function (string $role): void {
        $article = articleOf(articleAuthor(), 'pending');
        $user = userWithTwoFactor();
        $user->assignRole($role);

        asModerator($user)->postJson("/api/v1/admin/articles/{$article->uuid}/approve", ['version' => 1])->assertNotFound();
        asModerator($user)->getJson('/api/v1/admin/blog/queue')->assertNotFound();

        expect($article->fresh()->status)->toBe(ArticleStatus::PendingReview);
    })->with(['particulier', 'prestataire', 'entrepreneur']);

    it('refuse un modérateur dont la session n\'a pas présenté de code 2FA (403)', function (): void {
        $article = articleOf(articleAuthor(), 'pending');

        fromFrontend()->actingAs(blogModerator())->postJson("/api/v1/admin/articles/{$article->uuid}/approve", ['version' => 1])->assertForbidden();

        expect($article->fresh()->status)->toBe(ArticleStatus::PendingReview);
    });

    it('refuse un rédacteur sans double authentification (403)', function (): void {
        $withoutTwoFactor = User::factory()->create();
        $withoutTwoFactor->assignRole('redacteur');

        asModerator($withoutTwoFactor)->getJson('/api/v1/admin/blog/queue')->assertForbidden();
    });

    it('limite les décisions de chaque modérateur (429)', function (): void {
        config(['security.rate_limits.blog_moderation.per_hour' => 2]);
        $moderator = blogModerator();
        $articles = collect(range(1, 3))->map(fn () => articleOf(articleAuthor(), 'pending'));

        foreach ($articles->take(2) as $article) {
            asModerator($moderator)->postJson("/api/v1/admin/articles/{$article->uuid}/approve", ['version' => 1])->assertOk();
        }

        asModerator($moderator)->postJson("/api/v1/admin/articles/{$articles[2]->uuid}/approve", ['version' => 1])->assertTooManyRequests();
    });
});

describe('récapitulatif aux modérateurs', function (): void {
    it('prévient les rédacteurs et admins avec 2FA, au plus une fois par période, sans contenu', function (): void {
        $redacteur = blogModerator('redacteur');
        $admin = blogModerator('admin');
        $withoutTwoFactor = User::factory()->create();
        $withoutTwoFactor->assignRole('redacteur');
        articleOf(articleAuthor(), 'pending', ['title' => 'Un titre très confidentiel']);

        expect(app(NotifyModerators::class)->handle())->toBe(2)
            ->and(app(NotifyModerators::class)->handle())->toBe(0);

        Notification::assertSentTo([$redacteur, $admin], ModerationDigestNotification::class, function (ModerationDigestNotification $n) use ($admin): bool {
            $mail = $n->toMail($admin);

            return $n->counts === ['article' => 1, 'revision' => 0]
                && ! str_contains(implode(' ', $mail->introLines), 'confidentiel');
        });
        Notification::assertNotSentTo($withoutTwoFactor, ModerationDigestNotification::class);

        $this->travel(31)->minutes();
        expect(app(NotifyModerators::class)->handle())->toBe(0);

        articleOf(articleAuthor(), 'pending', ['submitted_at' => now()]);
        expect(app(NotifyModerators::class)->handle())->toBe(2);
    });
});
