<?php

use App\Models\AdminAuditLog;
use App\Models\Media;
use App\Models\User;
use App\Modules\Accounts\Models\ProviderProfile;
use App\Modules\Directory\Actions\BlockUser;
use App\Modules\Events\Actions\NotifyModerators;
use App\Modules\Events\Enums\CancelledBy;
use App\Modules\Events\Enums\EventNotice;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\RevisionStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRevision;
use App\Modules\Events\Notifications\EventNotification;
use App\Modules\Events\Notifications\ModerationDigestNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| Modération des événements et des révisions par un admin avec double authentification.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-12 09:00:00');
    Notification::fake();
});

/**
 * Révision en attente proposée par l'organisateur d'un événement publié (par HTTP).
 *
 * @param  array<string, mixed>  $changes
 */
function pendingRevisionOf(Event $event, array $changes): EventRevision
{
    test()->actingAs($event->user)->patchJson("/api/v1/me/events/{$event->uuid}", $changes)->assertStatus(202);

    return EventRevision::query()->where('event_id', $event->id)->where('status', 'pending')->sole();
}

describe('file d\'attente', function (): void {
    it('liste les événements et les révisions en attente, du plus ancien au plus récent', function (): void {
        $recent = Event::factory()->pendingReview()->create(['submitted_at' => now()->subHour()]);
        $old = Event::factory()->pendingReview()->create(['submitted_at' => now()->subDays(2)]);
        Event::factory()->create();
        $published = publishedEventOf();
        $this->travel(-1)->days();
        $revision = pendingRevisionOf($published, ['city' => 'Villeurbanne']);
        $this->travelBack();

        asModerator()->getJson('/api/v1/admin/events/queue')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.uuid', $old->uuid)
            ->assertJsonPath('data.1.kind', 'revision')
            ->assertJsonPath('data.1.uuid', $revision->uuid)
            ->assertJsonPath('data.1.event.uuid', $published->uuid)
            ->assertJsonPath('data.2.uuid', $recent->uuid)
            ->assertJsonPath('meta.counts', ['event' => 2, 'revision' => 1, 'announcement' => 0]);

        asModerator()->getJson('/api/v1/admin/events/queue?kind=revision')->assertOk()->assertJsonCount(1, 'data');
        asModerator()->getJson('/api/v1/admin/events/queue?kind=autre')->assertUnprocessable();
    });

    it('cache la modération aux autres rôles (404) et l\'interdit à un admin sans 2FA (403)', function (string $role): void {
        $user = eventOrganizer($role);

        $this->actingAs($user)->getJson('/api/v1/admin/events/queue')->assertNotFound();
    })->with(['particulier', 'prestataire', 'entrepreneur', 'redacteur']);

    it('exige la double authentification d\'un admin', function (): void {
        $this->actingAs(projectOwner('admin'))->getJson('/api/v1/admin/events/queue')->assertForbidden();
    });
});

describe('vérification d\'un événement', function (): void {
    it('montre le contenu complet, lien de connexion compris, les domaines et l\'historique de l\'organisateur', function (): void {
        $organizer = eventOrganizer('prestataire');
        publishedEventOf($organizer, ['external_url' => 'https://salon-compta.fr/programme']);
        Event::factory()->rejected()->create(['user_id' => $organizer->id]);
        app(BlockUser::class)->handle(User::factory()->create(), $organizer->id);
        $event = Event::factory()->pendingReview()->create([
            'user_id' => $organizer->id,
            'organizer_name' => null,
            'format' => 'hybrid',
            'online_url' => 'https://visio.exemple.fr/salle-privee',
            'external_url' => 'https://salon-compta.fr/inscription',
            'ticket_url' => null,
            'description' => 'Programme détaillé sur www.xn--pple-43d.com et questions sur forum.exemple.org pendant l\'atelier.',
        ]);

        $data = asModerator()->getJson("/api/v1/admin/events/{$event->uuid}")->assertOk()->json('data');

        expect($data['content']['online_url'])->toBe('https://visio.exemple.fr/salle-privee')
            ->and($data['version'])->toBe(1)
            ->and(collect($data['domains'])->keyBy('host')->all())->toMatchArray([
                'salon-compta.fr' => ['host' => 'salon-compta.fr', 'fields' => ['external_url'], 'punycode' => false, 'previously_approved' => true],
                'visio.exemple.fr' => ['host' => 'visio.exemple.fr', 'fields' => ['online_url'], 'punycode' => false, 'previously_approved' => false],
                'www.xn--pple-43d.com' => ['host' => 'www.xn--pple-43d.com', 'fields' => ['description'], 'punycode' => true, 'previously_approved' => false],
                'forum.exemple.org' => ['host' => 'forum.exemple.org', 'fields' => ['description'], 'punycode' => false, 'previously_approved' => false],
            ])
            ->and($data['organizer']['account'])->toMatchArray(['account_type' => 'prestataire', 'email_verified' => true])
            ->and($data['organizer']['provider']['display_name'])->toBe(ProviderProfile::query()->sole()->display_name)
            ->and($data['organizer']['events'])->toMatchArray(['published' => 1, 'rejected' => 1, 'pending_review' => 1])
            ->and($data['organizer']['messaging'])->toBe(['reports_received' => 0, 'reports_open' => 0, 'blocked_by_users' => 1]);

        expectNoPrivateFields($data);
        expect(json_encode($data))->not->toContain($organizer->email);
    });

    it('ne montre que le domaine du lien de connexion en dehors de la modération', function (): void {
        $event = publishedEventOf(null, ['format' => 'online', 'online_url' => 'https://visio.exemple.fr/salle-privee', 'venue_name' => null, 'address_line' => null, 'city' => null, 'country' => null, 'postal_code' => null]);

        asModerator()->getJson("/api/v1/admin/events/{$event->uuid}")
            ->assertOk()
            ->assertJsonPath('data.content.online_url', null)
            ->assertJsonPath('data.content.online_url_host', 'visio.exemple.fr');
    });

    it('ne montre pas un brouillon jamais soumis (404)', function (): void {
        $event = Event::factory()->create();

        asModerator()->getJson("/api/v1/admin/events/{$event->uuid}")->assertNotFound();
    });
});

describe('validation et refus d\'un événement', function (): void {
    it('publie l\'événement validé, trace la décision et prévient l\'organisateur', function (): void {
        $admin = moderator();
        $event = Event::factory()->pendingReview()->create(['user_id' => eventOrganizer()->id]);

        asModerator($admin)->postJson("/api/v1/admin/events/{$event->uuid}/approve", ['version' => 1])
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.decisions.0.action', 'events.event.approved')
            ->assertJsonPath('data.decisions.0.admin', $admin->name);

        expect($event->fresh())->status->toBe(EventStatus::Published)->slug->not->toBeNull()
            ->and(AdminAuditLog::query()->sole())->admin_id->toBe($admin->id)->version->toBe(1)
            ->and(securityLog('events.approved')[0]->context)->toMatchArray(['user_id' => $admin->id, 'event' => $event->uuid]);
        Notification::assertSentTo($event->user, EventNotification::class, fn (EventNotification $n): bool => $n->notice === EventNotice::EventApproved);
    });

    it('refuse une validation sur une version périmée (409)', function (): void {
        $event = Event::factory()->pendingReview()->create(['version' => 4]);

        asModerator()->postJson("/api/v1/admin/events/{$event->uuid}/approve", ['version' => 3])
            ->assertConflict()
            ->assertJsonPath('message', 'Cet élément a été modifié depuis votre lecture : rechargez-le avant de décider.');

        expect($event->fresh()->status)->toBe(EventStatus::PendingReview);
    });

    it('refuse de valider un événement qui n\'est plus en attente, déjà commencé, ou dont l\'organisateur a perdu son profil (409)', function (): void {
        $draft = Event::factory()->create();
        $started = Event::factory()->pendingReview()->ongoing()->create();
        $provider = eventOrganizer('prestataire');
        $orphan = Event::factory()->pendingReview()->create(['user_id' => $provider->id, 'organizer_name' => null]);
        ProviderProfile::query()->where('user_id', $provider->id)->update(['status' => 'draft']);

        foreach ([$draft, $started, $orphan] as $event) {
            asModerator()->postJson("/api/v1/admin/events/{$event->uuid}/approve", ['version' => 1])->assertStatus($event === $draft ? 404 : 409);
        }
    });

    it('exige la version et refuse un motif lors d\'une validation (422)', function (): void {
        $event = Event::factory()->pendingReview()->create();

        asModerator()->postJson("/api/v1/admin/events/{$event->uuid}/approve", [])->assertUnprocessable()->assertJsonValidationErrors(['version']);
        asModerator()->postJson("/api/v1/admin/events/{$event->uuid}/approve", ['version' => 1, 'reason' => 'Très bien.'])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    });

    it('refuse avec un motif obligatoire, communiqué à l\'organisateur', function (): void {
        $event = Event::factory()->pendingReview()->create();
        $reason = 'Le lien d\'inscription pointe vers un site sans rapport avec l\'atelier.';

        asModerator()->postJson("/api/v1/admin/events/{$event->uuid}/reject", ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        asModerator()->postJson("/api/v1/admin/events/{$event->uuid}/reject", ['version' => 1, 'reason' => 'Non.'])->assertUnprocessable();
        asModerator()->postJson("/api/v1/admin/events/{$event->uuid}/reject", ['version' => 1, 'reason' => '<b>Refusé</b> pour spam'])->assertUnprocessable();

        asModerator()->postJson("/api/v1/admin/events/{$event->uuid}/reject", ['version' => 1, 'reason' => $reason])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', $reason);

        expect(AdminAuditLog::query()->sole())->action->toBe('events.event.rejected')->reason->toBe($reason);
        Notification::assertSentTo($event->user, EventNotification::class, function (EventNotification $notification) use ($event, $reason): bool {
            return $notification->notice === EventNotice::EventRejected
                && in_array('Motif : '.$reason, $notification->toMail($event->user)->introLines, true);
        });
        // Le motif n'est jamais écrit dans le journal security.
        expect(json_encode(securityLog()))->not->toContain('site sans rapport');
    });

    it('limite les décisions de modération (429)', function (): void {
        config(['security.rate_limits.event_moderation.per_hour' => 1]);
        $admin = moderator();
        [$first, $second] = Event::factory()->pendingReview()->count(2)->create(['user_id' => eventOrganizer()->id])->all();

        asModerator($admin)->postJson("/api/v1/admin/events/{$first->uuid}/approve", ['version' => 1])->assertOk();
        asModerator($admin)->postJson("/api/v1/admin/events/{$second->uuid}/approve", ['version' => 1])->assertTooManyRequests();
    });
});

describe('révisions', function (): void {
    it('montre la version proposée et la version publiée côte à côte', function (): void {
        $event = publishedEventOf(null, ['format' => 'hybrid', 'online_url' => 'https://visio.exemple.fr/ancienne']);
        $revision = pendingRevisionOf($event, ['online_url' => 'https://visio.exemple.fr/nouvelle', 'title' => 'Atelier : la TVA sans stress']);

        asModerator()->getJson("/api/v1/admin/event-revisions/{$revision->uuid}")
            ->assertOk()
            ->assertJsonPath('data.changed_fields', ['title', 'online_url'])
            ->assertJsonPath('data.proposed.online_url', 'https://visio.exemple.fr/nouvelle')
            ->assertJsonPath('data.current.online_url', 'https://visio.exemple.fr/ancienne')
            ->assertJsonPath('data.proposed.title', 'Atelier : la TVA sans stress')
            ->assertJsonPath('data.current.title', 'Atelier : bien démarrer sa comptabilité');
    });

    it('applique la révision validée et prévient l\'organisateur et les inscrits d\'un changement de lieu', function (): void {
        Storage::fake('public');
        $event = publishedEventOf(null, ['version' => 2]);
        $attendee = projectOwner('particulier');
        registerTo($event, $attendee);
        $oldCover = Media::factory()->create(['user_id' => $event->user_id, 'purpose' => 'event_cover', 'attached_at' => now()]);
        $event->forceFill(['cover_media_id' => $oldCover->id])->save();
        $newCover = Media::factory()->create(['user_id' => $event->user_id, 'purpose' => 'event_cover']);
        $revision = pendingRevisionOf($event, ['venue_name' => 'Le Bureau des entrepreneurs', 'cover' => $newCover->uuid]);

        asModerator()->postJson("/api/v1/admin/event-revisions/{$revision->uuid}/approve", ['version' => 1])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        expect($event->fresh())
            ->venue_name->toBe('Le Bureau des entrepreneurs')
            ->version->toBe(3)
            ->cover_media_id->toBe($newCover->id)
            ->and(Media::query()->whereKey($oldCover->id)->exists())->toBeFalse()
            ->and($revision->fresh())->status->toBe(RevisionStatus::Approved)->pending_key->toBeNull()
            ->and(AdminAuditLog::query()->sole())->action->toBe('events.revision.approved')->subject_uuid->toBe($revision->uuid);
        Notification::assertSentTo($event->user, EventNotification::class, fn (EventNotification $n): bool => $n->notice === EventNotice::RevisionApproved);
        Notification::assertSentTo($attendee, EventNotification::class, fn (EventNotification $n): bool => $n->notice === EventNotice::Changed);
    });

    it('ne prévient pas les inscrits d\'une modification mineure', function (): void {
        $event = publishedEventOf();
        $attendee = projectOwner('particulier');
        registerTo($event, $attendee);
        $revision = pendingRevisionOf($event, ['title' => 'Atelier : la TVA sans stress']);

        asModerator()->postJson("/api/v1/admin/event-revisions/{$revision->uuid}/approve", ['version' => 1])->assertOk();

        Notification::assertNotSentTo($attendee, EventNotification::class);
    });

    it('refuse de valider une révision remplacée depuis la lecture de l\'admin (409)', function (): void {
        $event = publishedEventOf();
        $revision = pendingRevisionOf($event, ['city' => 'Villeurbanne']);
        pendingRevisionOf($event, ['external_url' => 'https://exemple.fr/programme']);

        asModerator()->postJson("/api/v1/admin/event-revisions/{$revision->uuid}/approve", ['version' => 1])->assertConflict();

        expect($event->fresh()->city)->toBe('Lyon');
    });

    it('refuse une révision devenue invalide : capacité inférieure aux inscrits (409)', function (): void {
        $event = publishedEventOf(null, ['capacity' => 5]);
        $revision = pendingRevisionOf($event, ['capacity' => 2]);
        registerTo($event);
        registerTo($event);
        registerTo($event);

        asModerator()->postJson("/api/v1/admin/event-revisions/{$revision->uuid}/approve", ['version' => 1])->assertConflict();
    });

    it('refuse une révision avec un motif : la version publiée reste affichée et la couverture proposée est supprimée', function (): void {
        Storage::fake('public');
        $event = publishedEventOf();
        $proposed = Media::factory()->create(['user_id' => $event->user_id, 'purpose' => 'event_cover']);
        $revision = pendingRevisionOf($event, ['title' => 'Gagnez 10 000 € par mois sans effort', 'cover' => $proposed->uuid]);
        $reason = 'Le titre est trompeur et ne décrit pas le contenu de l\'atelier.';

        asModerator()->postJson("/api/v1/admin/event-revisions/{$revision->uuid}/reject", ['version' => 1, 'reason' => $reason])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        expect($event->fresh()->title)->toBe('Atelier : bien démarrer sa comptabilité')
            ->and(Media::query()->whereKey($proposed->id)->exists())->toBeFalse();
        Notification::assertSentTo($event->user, EventNotification::class, fn (EventNotification $n): bool => $n->notice === EventNotice::RevisionRejected && $n->reason === $reason);

        $this->actingAs($event->user)->getJson("/api/v1/me/events/{$event->uuid}")
            ->assertJsonPath('data.pending_revision', null)
            ->assertJsonPath('data.latest_revision.status', 'rejected')
            ->assertJsonPath('data.latest_revision.rejection_reason', $reason);
    });
});

describe('annulation par un admin', function (): void {
    it('annule un événement publié avec un motif, prévient les inscrits et l\'organisateur', function (): void {
        $event = publishedEventOf();
        $attendee = projectOwner('particulier');
        registerTo($event, $attendee);
        $reason = 'Événement signalé comme une arnaque par plusieurs participants.';

        asModerator()->postJson("/api/v1/admin/events/{$event->uuid}/cancel", [])->assertUnprocessable();
        asModerator()->postJson("/api/v1/admin/events/{$event->uuid}/cancel", ['reason' => $reason, 'version' => 1])->assertUnprocessable();
        asModerator()->postJson("/api/v1/admin/events/{$event->uuid}/cancel", ['reason' => $reason])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancelled_by', 'admin');

        expect($event->fresh()->cancelled_by)->toBe(CancelledBy::Admin)
            ->and(AdminAuditLog::query()->sole())->action->toBe('events.event.cancelled')->reason->toBe($reason);
        Notification::assertSentTo($attendee, EventNotification::class, fn (EventNotification $n): bool => $n->notice === EventNotice::Cancelled && $n->reason === null);
        Notification::assertSentTo($event->user, EventNotification::class, fn (EventNotification $n): bool => $n->notice === EventNotice::CancelledByModeration && $n->reason === $reason);
    });
});

describe('récapitulatif aux admins', function (): void {
    it('prévient les admins au plus une fois toutes les 30 minutes, en regroupant les soumissions', function (): void {
        Cache::flush();
        $admins = [moderator(), projectOwner('admin')];
        $unverifiedAdmin = projectOwner('admin', verified: false);
        $organizer = eventOrganizer();

        Event::factory()->pendingReview()->create(['submitted_at' => now()]);
        $this->artisan('events:notify-moderators')->assertSuccessful();

        foreach ($admins as $admin) {
            Notification::assertSentToTimes($admin, ModerationDigestNotification::class, 1);
        }
        Notification::assertNotSentTo([$unverifiedAdmin, $organizer], ModerationDigestNotification::class);

        // Nouvelle soumission 10 minutes plus tard : regroupée, pas d'email.
        $this->travel(10)->minutes();
        Event::factory()->pendingReview()->create(['submitted_at' => now()]);
        expect(app(NotifyModerators::class)->handle())->toBe(0);

        // 30 minutes après le premier email : un seul email pour les éléments arrivés entre-temps.
        $this->travel(21)->minutes();
        expect(app(NotifyModerators::class)->handle())->toBe(2);
        Notification::assertSentToTimes($admins[0], ModerationDigestNotification::class, 2);

        // Rien de nouveau depuis : pas d'email, même après la période.
        $this->travel(40)->minutes();
        expect(app(NotifyModerators::class)->handle())->toBe(0);
    });

    it('ne contient ni titre ni contenu', function (): void {
        Cache::flush();
        $admin = moderator();
        Event::factory()->pendingReview()->create(['title' => 'Titre confidentiel en attente', 'submitted_at' => now()]);

        app(NotifyModerators::class)->handle();

        Notification::assertSentTo($admin, ModerationDigestNotification::class, function (ModerationDigestNotification $notification) use ($admin): bool {
            $mail = $notification->toMail($admin);

            return ! str_contains(json_encode($mail->toArray()), 'Titre confidentiel') && $notification->counts['event'] === 1;
        });
    });
});
