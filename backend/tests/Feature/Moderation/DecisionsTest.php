<?php

use App\Models\AdminAuditLog;
use App\Models\Media;
use App\Modules\Accounts\Models\ProviderProfile;
use App\Modules\Moderation\Enums\DecisionSource;
use App\Modules\Moderation\Enums\ModerationMail;
use App\Modules\Moderation\Enums\NoticeStatus;
use App\Modules\Moderation\Models\Decision;
use App\Modules\Moderation\Models\Notice;
use App\Modules\Moderation\Notifications\ModerationNotification;
use App\Modules\Moderation\Support\TrackingToken;
use App\Support\Moderation\ModerationTargets;
use App\Support\TwoFactorSession;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
| Décisions de modération (DSA art. 16 §5, 17, 18) : file, dossier d'un contenu, décision
| motivée, exposé des motifs, information des signalants, levée, trace d'un signalement aux
| autorités. Réservé aux admins avec 2FA de session.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-16 10:00:00');
    Notification::fake();
});

/**
 * Signalement sans compte d'un contenu public ; renvoie le jeton de suivi.
 */
function guestNotice(string $type, string $ref, string $category = 'scam_fraud', string $email = 'alex@example.org'): string
{
    return (string) test()->postJson('/api/v1/moderation/notices', [
        'target_type' => $type,
        'target_ref' => $ref,
        'category' => $category,
        'explanation' => 'Ce contenu renvoie vers un site frauduleux qui demande un paiement.',
        'name' => 'Alex Martin',
        'email' => $email,
        'good_faith' => true,
    ])->assertCreated()->json('tracking_token');
}

function contentVersion(string $type, string $ref): string
{
    return (string) ModerationTargets::get($type)->inspect($ref)?->version;
}

/**
 * @return array<string, mixed>
 */
function restrictPayload(string $type, string $ref, array $overrides = []): array
{
    return [
        'measure' => 'restrict_visibility',
        'ground' => 'terms',
        'category' => 'scam_fraud',
        'terms_clause' => 'prohibited_content',
        'facts' => 'L\'article renvoie vers un site qui demande un paiement pour des gains garantis.',
        'correction_hint' => 'Retirez le lien vers le site de paiement.',
        'version' => contentVersion($type, $ref),
        ...$overrides,
    ];
}

describe('file et dossier', function (): void {
    it('classe les contenus signalés, les urgents d\'abord, et signale les retards', function (): void {
        $standard = articleOf(articleAuthor(), 'published');
        $urgent = articleOf(articleAuthor(), 'published');
        guestNotice('article', $standard->slug);
        guestNotice('article', $standard->slug, email: 'autre@example.org');
        $this->travel(8)->days();
        guestNotice('article', $urgent->slug, 'violence_terrorism');

        asModerator()->getJson('/api/v1/admin/moderation/queue')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.target.ref', $urgent->slug)
            ->assertJsonPath('data.0.priority', 'urgent')
            ->assertJsonPath('data.0.overdue', false)
            ->assertJsonPath('data.1.notices_count', 2)
            ->assertJsonPath('data.1.overdue', true)
            ->assertJsonPath('meta.counts', ['open_notices' => 3, 'urgent_targets' => 1, 'overdue_targets' => 1, 'pending_appeals' => 0]);
    });

    it('montre le contenu, les signalements sans l\'email du signalant, et trace la consultation', function (): void {
        $admin = moderator();
        $article = articleOf(articleAuthor(), 'published');
        guestNotice('article', $article->slug);

        $response = asModerator($admin)->getJson("/api/v1/admin/moderation/targets/article/{$article->slug}")
            ->assertOk()
            ->assertJsonPath('data.content.label', $article->title)
            ->assertJsonPath('data.content.version', contentVersion('article', $article->slug))
            ->assertJsonPath('data.notices.0.reporter', ['kind' => 'guest', 'name' => 'Alex Martin'])
            ->assertJsonPath('data.notices.0.explanation', 'Ce contenu renvoie vers un site frauduleux qui demande un paiement.')
            ->assertJsonPath('data.owner.roles', ['entrepreneur']);

        expect($response->getContent())->not->toContain('alex@example.org');
        expect(AdminAuditLog::query()->where('action', 'moderation.target.viewed')->where('admin_id', $admin->id)->count())->toBe(1);
    });

    it('ne réaffiche aucune image d\'un contenu signalé comme abus sexuels sur mineurs', function (): void {
        $provider = browsingProvider();
        $slug = providerSlug($provider);
        ProviderProfile::query()->where('slug', $slug)->update(['logo_media_id' => Media::factory()->create(['user_id' => $provider->id])->id]);

        asModerator()->getJson("/api/v1/admin/moderation/targets/provider_profile/{$slug}")
            ->assertOk()
            ->assertJsonCount(1, 'data.content.images');

        $this->app['auth']->forgetGuards();
        guestNotice('provider_profile', $slug, 'child_sexual_abuse');

        asModerator()->getJson("/api/v1/admin/moderation/targets/provider_profile/{$slug}")
            ->assertOk()
            ->assertJsonPath('data.content.images', [])
            ->assertJsonPath('data.content.images_withheld', true);
    });

    it('répond 404 pour un type ou un contenu inconnu', function (): void {
        asModerator()->getJson('/api/v1/admin/moderation/targets/user/quelquun')->assertNotFound();
        asModerator()->getJson('/api/v1/admin/moderation/targets/article/inconnu')->assertNotFound();
        asModerator()->getJson('/api/v1/admin/moderation/decisions/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d')->assertNotFound();
    });
});

describe('restriction', function (): void {
    it('masque le contenu, motive la décision auprès de l\'auteur et informe chaque signalant', function (): void {
        $admin = moderator();
        $author = articleAuthor();
        $article = articleOf($author, 'published');
        $oldToken = guestNotice('article', $article->slug);
        $member = projectOwner('particulier');
        $this->actingAs($member)->postJson('/api/v1/moderation/notices', [
            'target_type' => 'article', 'target_ref' => $article->slug, 'category' => 'terms',
            'explanation' => 'Article publicitaire sans rapport avec la création d\'entreprise.', 'good_faith' => true,
        ])->assertCreated();
        $guestNotice = Notice::query()->whereNull('reporter_id')->sole();

        $response = asModerator($admin)->postJson("/api/v1/admin/moderation/targets/article/{$article->slug}/decisions", restrictPayload('article', $article->slug))
            ->assertCreated()
            ->assertJsonPath('data.measure', 'restrict_visibility')
            ->assertJsonPath('data.source', 'notice')
            ->assertJsonPath('data.automated', false)
            ->assertJsonPath('data.terms_clause.key', 'prohibited_content')
            ->assertJsonPath('data.redress.internal_appeal.deadline_at', '2027-05-16T10:00:00Z')
            ->assertJsonPath('data.notices_count', 2);
        $uuid = $response->json('data.uuid');

        $this->getJson("/api/v1/articles/{$article->slug}")->assertNotFound();
        expect(Notice::query()->where('status', NoticeStatus::Decided->value)->count())->toBe(2)
            ->and(Notice::query()->whereNotNull('active_key')->count())->toBe(0);

        // Exposé des motifs à l'auteur (art. 17 §3).
        Notification::assertSentTo($author, ModerationNotification::class, function (ModerationNotification $n) use ($author): bool {
            $lines = implode("\n", $n->toMail($author)->introLines);

            return $n->mail === ModerationMail::DecisionTaken
                && $n->notificationCategory() === 'moderation.decisions'
                && str_contains($lines, 'Origine : un ou plusieurs signalements.')
                && str_contains($lines, 'conditions générales d\'utilisation, Contenus interdits')
                && str_contains($lines, 'Faits et motifs : L\'article renvoie')
                && str_contains($lines, 'Ce que vous pouvez corriger : Retirez le lien')
                && str_contains($lines, 'sans outil automatisé')
                && str_contains($lines, 'jusqu\'au 16 mai 2027')
                && ! str_contains($lines, 'Alex')
                && ! str_contains($lines, 'Article publicitaire');
        });

        // Décision aux signalants (art. 16 §5), sans les faits ; nouveau jeton pour le signalant sans compte.
        Notification::assertSentTo($member, ModerationNotification::class, fn (ModerationNotification $n): bool => $n->mail === ModerationMail::NoticeDecided);
        Notification::assertSentOnDemand(ModerationNotification::class, function (ModerationNotification $n, array $channels, AnonymousNotifiable $to) use ($guestNotice, $article): bool {
            if ($n->mail !== ModerationMail::NoticeDecided) {
                return false;
            }

            $mail = json_encode($n->toMail($to)->toArray());
            preg_match('/token=([A-Za-z0-9]{48})/', (string) $n->toMail($to)->actionUrl, $match);

            return $to->routes['mail'] === 'alex@example.org'
                && TrackingToken::matches($guestNotice->fresh()->tracking_token_hash, $match[1] ?? '')
                && ! str_contains($mail, 'paiement pour des gains')
                && ! str_contains($mail, $article->title);
        });
        $this->postJson("/api/v1/moderation/notices/{$guestNotice->uuid}/lookup", ['token' => $oldToken])->assertNotFound();

        // L'auteur lit l'exposé des motifs ; personne d'autre.
        $this->actingAs($author)->getJson("/api/v1/me/moderation/decisions/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.facts', 'L\'article renvoie vers un site qui demande un paiement pour des gains garantis.')
            ->assertJsonPath('data.target.label', $article->title)
            ->assertJsonPath('data.redress.internal_appeal.available', true)
            ->assertJsonPath('data.redress.court', true);
        $this->actingAs($author)->getJson('/api/v1/me/moderation/decisions')->assertOk()->assertJsonCount(1, 'data');
        foreach ([$member, $admin] as $other) {
            $this->actingAs($other)->getJson("/api/v1/me/moderation/decisions/{$uuid}")->assertNotFound();
        }

        // Journal d'audit avec les faits ; canal security sans les faits.
        expect(AdminAuditLog::query()->where('action', 'moderation.decision.restricted')->sole()->reason)->toContain('gains garantis');
        $log = securityLog('moderation.decision_taken');
        expect($log)->toHaveCount(1)
            ->and($log[0]->context)->toMatchArray(['user_id' => $admin->id, 'decision' => $uuid, 'measure' => 'restrict_visibility', 'notices' => 2])
            ->and(json_encode($log[0]->context))->not->toContain('gains garantis');
    });

    it('décide de sa propre initiative, sans signalement', function (): void {
        $project = publishedProjectOf();

        asModerator()->postJson("/api/v1/admin/moderation/targets/project/{$project->uuid}/decisions", restrictPayload('project', $project->uuid, [
            'ground' => 'illegal', 'terms_clause' => null, 'legal_reference' => 'Code pénal, article 313-1 (escroquerie)',
        ]))->assertCreated()->assertJsonPath('data.source', 'own_initiative')->assertJsonPath('data.legal_reference', 'Code pénal, article 313-1 (escroquerie)');

        expect(Decision::query()->sole()->source)->toBe(DecisionSource::OwnInitiative);
        Notification::assertSentTo($project->user, ModerationNotification::class, fn (ModerationNotification $n): bool => str_contains(implode(' ', $n->toMail($project->user)->introLines), 'Code pénal, article 313-1'));
    });

    it('valide l\'exposé des motifs (422)', function (array $overrides, string $field): void {
        $article = articleOf(articleAuthor(), 'published');

        asModerator()->postJson("/api/v1/admin/moderation/targets/article/{$article->slug}/decisions", restrictPayload('article', $article->slug, $overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        expect(Decision::query()->count())->toBe(0);
    })->with([
        'mesure inconnue' => [['measure' => 'ban'], 'measure'],
        'sans fondement' => [['ground' => null], 'ground'],
        'illicite sans référence' => [['ground' => 'illegal', 'terms_clause' => null], 'legal_reference'],
        'CGU sans clause' => [['terms_clause' => null], 'terms_clause'],
        'clause inconnue' => [['terms_clause' => 'article-42'], 'terms_clause'],
        'référence avec CGU' => [['legal_reference' => 'Code pénal'], 'legal_reference'],
        'faits trop courts' => [['facts' => 'Spam.'], 'facts'],
        'faits en HTML' => [['facts' => '<b>Contenu</b> frauduleux qui renvoie vers un site de paiement.'], 'facts'],
        'sans version' => [['version' => null], 'version'],
        'catégorie inconnue' => [['category' => 'autre-chose'], 'category'],
    ]);

    it('refuse une version qui n\'est plus la bonne (409)', function (): void {
        $article = articleOf(articleAuthor(), 'published');

        asModerator()->postJson("/api/v1/admin/moderation/targets/article/{$article->slug}/decisions", restrictPayload('article', $article->slug, ['version' => str_repeat('a', 64)]))
            ->assertConflict();
    });

    it('refuse de masquer un contenu déjà masqué ou supprimé (409), et son propre contenu (403)', function (): void {
        $article = articleOf(articleAuthor(), 'published');
        $payload = restrictPayload('article', $article->slug);
        asModerator()->postJson("/api/v1/admin/moderation/targets/article/{$article->slug}/decisions", $payload)->assertCreated();
        asModerator()->postJson("/api/v1/admin/moderation/targets/article/{$article->slug}/decisions", $payload)->assertConflict();

        $gone = articleOf(articleAuthor(), 'published');
        $slug = $gone->slug;
        $version = contentVersion('article', $slug);
        $gone->delete();
        asModerator()->postJson("/api/v1/admin/moderation/targets/article/{$slug}/decisions", restrictPayload('article', 'x', ['version' => $version]))->assertConflict();

        $admin = moderator();
        $own = articleOf($admin, 'published');
        asModerator($admin)->postJson("/api/v1/admin/moderation/targets/article/{$own->slug}/decisions", restrictPayload('article', $own->slug))->assertForbidden();
        expect(securityLog('moderation.self_moderation_denied'))->toHaveCount(1);
    });
});

describe('aucune mesure', function (): void {
    it('clôt les signalements et informe le signalant, qui peut contester, sans prévenir l\'auteur', function (): void {
        $author = articleAuthor();
        $article = articleOf($author, 'published');
        $member = projectOwner('particulier');
        $this->actingAs($member)->postJson('/api/v1/moderation/notices', [
            'target_type' => 'article', 'target_ref' => $article->slug, 'category' => 'other',
            'explanation' => 'Je ne suis pas d\'accord avec cet article sur les statuts.', 'good_faith' => true,
        ])->assertCreated();

        asModerator()->postJson("/api/v1/admin/moderation/targets/article/{$article->slug}/decisions", [
            'measure' => 'no_action',
            'facts' => 'Un désaccord d\'opinion ne rend pas le contenu illicite ni contraire aux CGU.',
            'version' => contentVersion('article', $article->slug),
            'manifestly_unfounded' => true,
        ])->assertCreated();

        $this->getJson("/api/v1/articles/{$article->slug}")->assertOk();
        expect(Notice::query()->sole()->manifestly_unfounded)->toBeTrue();
        Notification::assertNotSentTo($author, ModerationNotification::class);
        Notification::assertSentTo($member, ModerationNotification::class, fn (ModerationNotification $n): bool => $n->mail === ModerationMail::NoticeDecided
            && str_contains(implode(' ', $n->toMail($member)->introLines), 'aucune mesure')
            && str_contains(implode(' ', $n->toMail($member)->introLines), 'jusqu\'au 16 mai 2027'));

        $this->actingAs($member)->getJson('/api/v1/me/moderation/notices')
            ->assertOk()
            ->assertJsonPath('data.0.decision.measure', 'no_action')
            ->assertJsonPath('data.0.decision.appeal.available', true)
            ->assertJsonMissingPath('data.0.decision.facts');
    });

    it('refuse une absence de mesure sans signalement en cours (409) et les champs d\'une restriction (422)', function (): void {
        $article = articleOf(articleAuthor(), 'published');
        $url = "/api/v1/admin/moderation/targets/article/{$article->slug}/decisions";
        $payload = ['measure' => 'no_action', 'facts' => 'Aucun élément illicite ni contraire aux CGU dans ce contenu.', 'version' => contentVersion('article', $article->slug)];

        asModerator()->postJson($url, $payload)->assertConflict();
        asModerator()->postJson($url, [...$payload, 'ground' => 'terms', 'correction_hint' => 'Rien à corriger ici.'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ground', 'correction_hint']);
    });
});

describe('levée et autorités', function (): void {
    it('lève une restriction, rend le contenu et prévient l\'auteur ; une seule fois', function (): void {
        $provider = browsingProvider();
        $slug = providerSlug($provider);
        $uuid = asModerator()->postJson("/api/v1/admin/moderation/targets/provider_profile/{$slug}/decisions", restrictPayload('provider_profile', $slug))
            ->assertCreated()->json('data.uuid');
        $this->getJson("/api/v1/providers/{$slug}")->assertNotFound();

        asModerator()->postJson("/api/v1/admin/moderation/decisions/{$uuid}/lift", ['reason' => 'Le prestataire a corrigé son profil.'])
            ->assertOk()
            ->assertJsonPath('data.lift_reason', 'Le prestataire a corrigé son profil.');
        $this->getJson("/api/v1/providers/{$slug}")->assertOk();
        Notification::assertSentTo($provider, ModerationNotification::class, fn (ModerationNotification $n): bool => $n->mail === ModerationMail::RestrictionLifted);

        asModerator()->postJson("/api/v1/admin/moderation/decisions/{$uuid}/lift", ['reason' => 'Le prestataire a corrigé son profil.'])->assertConflict();
        asModerator()->postJson("/api/v1/admin/moderation/decisions/{$uuid}/lift", ['reason' => 'Court'])->assertUnprocessable();
        expect(securityLog('moderation.restriction_lifted'))->toHaveCount(1);
    });

    it('trace une transmission aux autorités, une fois, sans rien envoyer', function (): void {
        $article = articleOf(articleAuthor(), 'published');
        guestNotice('article', $article->slug, 'violence_terrorism');
        $notice = Notice::query()->sole();

        asModerator()->postJson("/api/v1/admin/moderation/notices/{$notice->uuid}/referral", ['authority' => 'pharos'])
            ->assertOk()
            ->assertJsonPath('data.referred_at', '2026-11-16T10:00:00Z');
        asModerator()->postJson("/api/v1/admin/moderation/notices/{$notice->uuid}/referral", ['authority' => 'pharos'])->assertConflict();
        asModerator()->postJson("/api/v1/admin/moderation/notices/{$notice->uuid}/referral", ['authority' => 'journal'])->assertUnprocessable();

        expect(securityLog('moderation.law_enforcement_referred')[0]->context)->toMatchArray(['notice' => $notice->uuid, 'authority' => 'pharos'])
            ->and(AdminAuditLog::query()->where('action', 'moderation.notice.referred')->count())->toBe(1);
    });
});

describe('accès', function (): void {
    it('cache la modération des signalements aux autres rôles (404), rédacteur compris', function (string $role): void {
        $user = userWithTwoFactor();
        $user->assignRole($role);

        fromFrontend()->actingAs($user)->withSession([TwoFactorSession::KEY => time()])
            ->getJson('/api/v1/admin/moderation/queue')
            ->assertNotFound();
    })->with(['particulier', 'prestataire', 'entrepreneur', 'redacteur']);

    it('limite les décisions par admin (429)', function (): void {
        config(['security.rate_limits.moderation_admin.per_hour' => 1]);
        $admin = moderator();

        asModerator($admin)->postJson('/api/v1/admin/moderation/decisions/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d/lift', ['reason' => 'Correction de l\'équipe.'])->assertNotFound();
        asModerator($admin)->postJson('/api/v1/admin/moderation/decisions/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d/lift', ['reason' => 'Correction de l\'équipe.'])->assertTooManyRequests();
    });
});
