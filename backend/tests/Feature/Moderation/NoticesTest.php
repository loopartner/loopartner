<?php

use App\Modules\Moderation\Enums\NoticePriority;
use App\Modules\Moderation\Enums\NoticeSource;
use App\Modules\Moderation\Enums\NoticeStatus;
use App\Modules\Moderation\Models\Notice;
use App\Modules\Moderation\Notifications\ModerationNotification;
use App\Modules\Moderation\Notifications\UrgentNoticeAlert;
use App\Modules\Moderation\Support\TrackingToken;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
| Signalement d'un contenu (DSA art. 16) : formulaire public avec ou sans compte, accusé de
| réception, suivi par jeton, alerte des signalements urgents, limites et confidentialité.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-16 10:00:00');
    Notification::fake();
});

/**
 * @return array<string, mixed>
 */
function noticePayload(string $type, string $ref, array $overrides = []): array
{
    return [
        'target_type' => $type,
        'target_ref' => $ref,
        'category' => 'scam_fraud',
        'explanation' => 'Ce contenu promet des gains garantis et renvoie vers un site frauduleux.',
        'location_detail' => 'Deuxième paragraphe.',
        'name' => 'Alex Martin',
        'email' => 'Alex.Martin@example.org',
        'good_faith' => true,
        ...$overrides,
    ];
}

function publishedArticleRef(): string
{
    return (string) articleOf(articleAuthor(), 'published')->slug;
}

describe('GET /moderation/info', function (): void {
    it('publie les points de contact, les catégories, les contenus signalables et le délai de recours', function (): void {
        config(['moderation.contact.users_email' => 'contact@loopartner.com', 'moderation.contact.authorities_email' => 'legal@loopartner.com']);

        $this->getJson('/api/v1/moderation/info?locale=en')
            ->assertOk()
            ->assertHeader('Vary', 'Accept-Language')
            ->assertJsonPath('data.contact.users_email', 'contact@loopartner.com')
            ->assertJsonPath('data.contact.authorities_email', 'legal@loopartner.com')
            ->assertJsonPath('data.contact.languages', ['fr', 'en'])
            ->assertJsonPath('data.appeal_window_months', 6)
            ->assertJsonPath('data.automated_decisions', false)
            ->assertJsonPath('data.categories.5', ['key' => 'child_sexual_abuse', 'label' => 'Child sexual abuse', 'priority' => 'urgent', 'identity_optional' => true])
            ->assertJsonPath('data.target_types.0', ['key' => 'provider_profile', 'label' => 'provider profile']);
    });
});

describe('signalement sans compte', function (): void {
    it('enregistre le signalement, copie le contenu en texte et envoie un accusé de réception avec le lien de suivi', function (): void {
        $article = articleOf(articleAuthor(), 'published');

        $response = $this->postJson('/api/v1/moderation/notices', noticePayload('article', $article->slug))
            ->assertCreated()
            ->assertJsonPath('data.target', ['type' => 'article', 'type_label' => 'article', 'ref' => $article->slug])
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.decision', null);

        $token = $response->json('tracking_token');
        expect($token)->toBeString()->toHaveLength(TrackingToken::LENGTH);
        expectNoPrivateFields($response->json());
        expect($response->getContent())->not->toContain('Alex')->not->toContain('frauduleux');

        $notice = Notice::query()->sole();
        expect($notice->target_owner_id)->toBe($article->user_id)
            ->and($notice->priority)->toBe(NoticePriority::Standard)
            ->and($notice->source)->toBe(NoticeSource::Public)
            ->and($notice->reporter_name)->toBe('Alex Martin')
            ->and($notice->reporter_email)->toBe('alex.martin@example.org')
            ->and($notice->content_label)->toBe($article->title)
            ->and($notice->content_snapshot)->toContain($article->title)
            ->and($notice->good_faith_confirmed_at)->not->toBeNull()
            ->and($notice->due_at->toIso8601ZuluString())->toBe('2026-11-23T10:00:00Z')
            ->and(TrackingToken::matches($notice->tracking_token_hash, $token))->toBeTrue();

        // Chiffré en base : ni le nom, ni l'email, ni l'explication en clair.
        $raw = (array) DB::table('moderation_notices')->first();
        expect(json_encode($raw))->not->toContain('Alex')->not->toContain('example.org')->not->toContain('frauduleux');

        Notification::assertSentOnDemand(ModerationNotification::class, function (ModerationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($notice, $token): bool {
            $mail = $notification->toMail($notifiable);

            return $notifiable->routes['mail'] === 'alex.martin@example.org'
                && $notification->notificationCategory() === 'moderation.notices'
                && str_contains((string) $mail->actionUrl, "/moderation/notices/{$notice->uuid}#token={$token}")
                && ! str_contains(json_encode($mail->toArray()), 'frauduleux');
        });

        $log = securityLog('moderation.notice_submitted');
        expect($log)->toHaveCount(1)
            ->and($log[0]->context)->toMatchArray(['user_id' => null, 'notice' => $notice->uuid, 'target_type' => 'article', 'category' => 'scam_fraud', 'priority' => 'standard', 'source' => 'public'])
            ->and(json_encode($log[0]->context))->not->toContain('Alex')->not->toContain('example.org')->not->toContain('frauduleux');
    });

    it('exige le nom, l\'email et la déclaration de bonne foi, sauf pour les abus sexuels sur mineurs', function (): void {
        $ref = publishedArticleRef();

        $this->postJson('/api/v1/moderation/notices', noticePayload('article', $ref, ['name' => null, 'email' => null, 'good_faith' => false]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'good_faith']);

        $this->postJson('/api/v1/moderation/notices', noticePayload('article', $ref, ['category' => 'child_sexual_abuse', 'name' => null, 'email' => null]))
            ->assertCreated()
            ->assertJsonStructure(['tracking_token']);

        Notification::assertNothingSentTo(new AnonymousNotifiable);
    });

    it('valide chaque champ (422)', function (array $overrides, string $field): void {
        $this->postJson('/api/v1/moderation/notices', noticePayload('article', publishedArticleRef(), $overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        expect(Notice::query()->count())->toBe(0);
    })->with([
        'type inconnu' => [['target_type' => 'user'], 'target_type'],
        'message (privé)' => [['target_type' => 'message'], 'target_type'],
        'référence invalide' => [['target_ref' => '../etc/passwd'], 'target_ref'],
        'catégorie inconnue' => [['category' => 'je-n-aime-pas'], 'category'],
        'explication trop courte' => [['explanation' => 'Pas bien.'], 'explanation'],
        'explication trop longue' => [['explanation' => str_repeat('a', 5001)], 'explanation'],
        'explication en HTML' => [['explanation' => '<script>alert(1)</script> contenu frauduleux signalé'], 'explanation'],
        'caractère invisible' => [['explanation' => "Contenu frauduleux\u{202E} signalé ici même"], 'explanation'],
        'email invalide' => [['email' => 'pas-un-email'], 'email'],
        'champ piège rempli' => [['homepage' => 'https://spam.example'], 'homepage'],
    ]);

    it('répond 404 pour un contenu inconnu, non publié ou déjà masqué', function (): void {
        $draft = articleOf(articleAuthor());
        $restricted = articleOf(articleAuthor(), 'published');
        restrictContent('article', $restricted->slug);

        foreach (['inconnu', (string) $draft->uuid, $restricted->slug] as $ref) {
            $this->postJson('/api/v1/moderation/notices', noticePayload('article', $ref))->assertNotFound();
        }
    });

    it('ignore les champs fixés par la plateforme', function (): void {
        $this->postJson('/api/v1/moderation/notices', noticePayload('article', publishedArticleRef(), [
            'status' => 'decided', 'priority' => 'urgent', 'due_at' => '2030-01-01', 'reporter_id' => 1,
            'target_owner_id' => 1, 'source' => 'in_app', 'decision_id' => 1,
        ]))->assertCreated();

        $notice = Notice::query()->sole();
        expect($notice->status)->toBe(NoticeStatus::Open)
            ->and($notice->priority)->toBe(NoticePriority::Standard)
            ->and($notice->reporter_id)->toBeNull()
            ->and($notice->source)->toBe(NoticeSource::Public)
            ->and($notice->decision_id)->toBeNull();
    });

    it('limite le corps de la requête (413)', function (): void {
        $this->postJson('/api/v1/moderation/notices', noticePayload('article', publishedArticleRef(), ['location_detail' => str_repeat('a', 40000)]))
            ->assertStatus(413);
    });
});

describe('suivi par jeton', function (): void {
    it('montre le signalement avec le bon jeton, 404 sinon', function (): void {
        $token = $this->postJson('/api/v1/moderation/notices', noticePayload('article', publishedArticleRef()))->json('tracking_token');
        $uuid = Notice::query()->sole()->uuid;

        $this->postJson("/api/v1/moderation/notices/{$uuid}/lookup", ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.uuid', $uuid);
        $this->postJson("/api/v1/moderation/notices/{$uuid}/lookup", ['token' => str_repeat('a', TrackingToken::LENGTH)])->assertNotFound();
        $this->postJson('/api/v1/moderation/notices/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d/lookup', ['token' => $token])->assertNotFound();
        $this->postJson("/api/v1/moderation/notices/{$uuid}/lookup", [])->assertUnprocessable();
    });

    it('limite les consultations par IP (429)', function (): void {
        config(['security.rate_limits.moderation_lookups.per_hour' => 2]);
        $uuid = '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d';

        $this->postJson("/api/v1/moderation/notices/{$uuid}/lookup", ['token' => str_repeat('a', 48)])->assertNotFound();
        $this->postJson("/api/v1/moderation/notices/{$uuid}/lookup", ['token' => str_repeat('a', 48)])->assertNotFound();
        $this->postJson("/api/v1/moderation/notices/{$uuid}/lookup", ['token' => str_repeat('a', 48)])->assertTooManyRequests();
    });
});

describe('signalement avec un compte', function (): void {
    it('prend l\'identité du compte, refuse un second signalement en cours et liste mes signalements', function (): void {
        $reporter = projectOwner('particulier');
        $ref = publishedArticleRef();
        $payload = noticePayload('article', $ref, ['name' => null, 'email' => null]);
        unset($payload['name'], $payload['email']);

        $response = $this->actingAs($reporter)->postJson('/api/v1/moderation/notices', $payload)->assertCreated();
        expect($response->json('tracking_token'))->toBeNull();

        $this->actingAs($reporter)->postJson('/api/v1/moderation/notices', $payload)
            ->assertConflict()
            ->assertJsonPath('message', 'Vous avez déjà signalé ce contenu.');

        $notice = Notice::query()->sole();
        expect($notice->reporter_id)->toBe($reporter->id)
            ->and($notice->reporter_name)->toBeNull()
            ->and($notice->reporter_email)->toBeNull()
            ->and($notice->tracking_token_hash)->toBeNull();
        Notification::assertNothingSent();

        $this->actingAs($reporter)->getJson('/api/v1/me/moderation/notices')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $notice->uuid);
        $this->actingAs($reporter)->getJson("/api/v1/me/moderation/notices/{$notice->uuid}")->assertOk();

        foreach ([projectOwner('particulier'), moderator()] as $other) {
            $this->actingAs($other)->getJson("/api/v1/me/moderation/notices/{$notice->uuid}")->assertNotFound();
        }
    });

    it('refuse le nom et l\'email saisis, et le signalement de son propre contenu', function (): void {
        $author = articleAuthor();
        $article = articleOf($author, 'published');

        $this->actingAs(projectOwner('particulier'))->postJson('/api/v1/moderation/notices', noticePayload('article', $article->slug))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email']);

        $payload = noticePayload('article', $article->slug);
        unset($payload['name'], $payload['email']);
        $this->actingAs($author)->postJson('/api/v1/moderation/notices', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['target_ref']);
    });

    it('ne laisse signaler un projet qu\'aux prestataires qui le voient', function (): void {
        $project = publishedProjectOf();
        $payload = noticePayload('project', $project->uuid);
        unset($payload['name'], $payload['email']);

        $this->postJson('/api/v1/moderation/notices', noticePayload('project', $project->uuid))->assertNotFound();
        $this->actingAs(projectOwner('entrepreneur'))->postJson('/api/v1/moderation/notices', $payload)->assertNotFound();
        $this->actingAs(browsingProvider())->postJson('/api/v1/moderation/notices', $payload)->assertCreated();
    });
});

describe('signalements urgents', function (): void {
    it('alerte immédiatement les admins avec 2FA, une fois par contenu, sans aucun contenu', function (): void {
        $admin = moderator();
        $adminWithout2fa = projectOwner('admin');
        $ref = publishedArticleRef();

        $this->postJson('/api/v1/moderation/notices', noticePayload('article', $ref, ['category' => 'violence_terrorism']))->assertCreated();
        $this->postJson('/api/v1/moderation/notices', noticePayload('article', $ref, ['category' => 'violence_terrorism', 'email' => 'autre@example.org']))->assertCreated();

        Notification::assertSentToTimes($admin, UrgentNoticeAlert::class, 1);
        Notification::assertNotSentTo($adminWithout2fa, UrgentNoticeAlert::class);
        Notification::assertSentTo($admin, UrgentNoticeAlert::class, function (UrgentNoticeAlert $alert) use ($admin, $ref): bool {
            $mail = json_encode($alert->toMail($admin)->toArray());

            return $alert->notificationCategory() === 'moderation.urgent_alerts'
                && str_contains($mail, '24 heures')
                && ! str_contains($mail, $ref)
                && ! str_contains($mail, 'frauduleux');
        });

        $notice = Notice::query()->first();
        expect($notice->priority)->toBe(NoticePriority::Urgent)
            ->and($notice->due_at->toIso8601ZuluString())->toBe('2026-11-17T10:00:00Z');
    });
});

describe('limites de débit', function (): void {
    it('limite les signalements par IP et journalise le dépassement (429)', function (): void {
        config(['security.rate_limits.moderation_notices.per_hour_per_ip' => 2]);
        $ref = publishedArticleRef();

        foreach (['a@example.org', 'b@example.org'] as $email) {
            $this->postJson('/api/v1/moderation/notices', noticePayload('article', $ref, ['email' => $email]))->assertCreated();
        }

        $this->postJson('/api/v1/moderation/notices', noticePayload('article', $ref, ['email' => 'c@example.org']))->assertTooManyRequests();
        expect(securityLog('moderation.notice_limit_exceeded'))->toHaveCount(1);
    });

    it('limite les signalements par email, quelle que soit l\'IP (429)', function (): void {
        config(['security.rate_limits.moderation_notices.per_day_per_email' => 1]);

        $this->postJson('/api/v1/moderation/notices', noticePayload('article', publishedArticleRef()))->assertCreated();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
            ->postJson('/api/v1/moderation/notices', noticePayload('article', publishedArticleRef(), ['email' => 'ALEX.martin@example.org']))
            ->assertTooManyRequests();
    });

    it('limite les signalements par compte (429)', function (): void {
        config(['security.rate_limits.moderation_notices.per_day_per_user' => 1]);
        $reporter = projectOwner('particulier');
        $payload = fn (): array => array_diff_key(noticePayload('article', publishedArticleRef()), ['name' => 1, 'email' => 1]);

        $this->actingAs($reporter)->postJson('/api/v1/moderation/notices', $payload())->assertCreated();
        $this->actingAs($reporter)->postJson('/api/v1/moderation/notices', $payload())->assertTooManyRequests();
    });
});

describe('reprise des signalements de la messagerie', function (): void {
    it('recopie abuse_reports dans moderation_notices, chiffrés, puis supprime l\'ancienne table', function (): void {
        [$owner, $provider, $conversation] = acceptedConversation();
        $message = postMessage($provider, $conversation, 'Payez-moi hors plateforme.');
        $migration = require database_path('migrations/2026_11_16_120200_move_abuse_reports_to_moderation_notices.php');

        $migration->down();
        DB::table('abuse_reports')->insert([
            ['uuid' => '1b9d6bcd-bbfd-4b2d-9b5d-ab8dfbbd4bed', 'reporter_id' => $owner->id, 'reported_user_id' => $provider->id, 'target_type' => 'message', 'target_id' => $message->id, 'reason' => 'off_platform', 'comment' => 'Hors site', 'content_snapshot' => 'Payez-moi hors plateforme.', 'status' => 'open', 'closed_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['uuid' => '6ec0bd7f-11c0-43da-975e-2a8ad9ebae0b', 'reporter_id' => null, 'reported_user_id' => null, 'target_type' => 'match_request', 'target_id' => 999, 'reason' => 'harassment', 'comment' => null, 'content_snapshot' => null, 'status' => 'closed', 'closed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $migration->up();

        expect(DB::getSchemaBuilder()->hasTable('abuse_reports'))->toBeFalse();

        $open = Notice::query()->where('uuid', '1b9d6bcd-bbfd-4b2d-9b5d-ab8dfbbd4bed')->sole();
        expect($open->target_ref)->toBe($message->uuid)
            ->and($open->target_owner_id)->toBe($provider->id)
            ->and($open->category)->toBe('terms')
            ->and($open->source_reason)->toBe('off_platform')
            ->and($open->source)->toBe(NoticeSource::InApp)
            ->and($open->explanation)->toBe('Hors site')
            ->and($open->content_snapshot)->toBe('Payez-moi hors plateforme.')
            ->and($open->status)->toBe(NoticeStatus::Open)
            ->and($open->active_key)->not->toBeNull();

        $closed = Notice::query()->where('uuid', '6ec0bd7f-11c0-43da-975e-2a8ad9ebae0b')->sole();
        expect($closed->status)->toBe(NoticeStatus::Dismissed)
            ->and($closed->category)->toBe('hate_harassment')
            ->and($closed->closed_at)->not->toBeNull();

        // Même empreinte que l'application : un nouveau signalement du même message est refusé.
        $this->actingAs($owner)
            ->postJson("/api/v1/me/conversations/{$conversation->uuid}/messages/{$message->uuid}/report", ['reason' => 'spam'])
            ->assertConflict();
    });
});
