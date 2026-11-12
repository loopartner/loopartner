<?php

use App\Models\AdminAuditLog;
use App\Models\Media;
use App\Modules\Accounts\Actions\DeleteAccount;
use App\Modules\Events\Actions\ApproveEvent;
use App\Modules\Events\Enums\AnnouncementStatus;
use App\Modules\Events\Enums\CancelledBy;
use App\Modules\Events\Enums\EventNotice;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\RevisionStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAnnouncement;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventRevision;
use App\Modules\Events\Notifications\EventNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| Cycle de vie : rappels, passage au statut "past", minimisation des données et suppression de
| compte.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-12 09:00:00');
    Notification::fake();
});

describe('rappels', function (): void {
    it('envoie un seul rappel aux inscrits 24 heures avant, sans doublon', function (): void {
        $this->travelTo(now()->subDays(3));
        $event = publishedEventOf(null, ['starts_at' => '2026-11-13 08:00:00', 'ends_at' => '2026-11-13 10:00:00']);
        $early = projectOwner('particulier');
        registerTo($event, $early);
        $this->travelBack();
        $late = projectOwner('particulier');
        registerTo($event, $late);
        $farAway = publishedEventOf(null, ['starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHour()]);
        registerTo($farAway);

        $this->artisan('events:send-reminders')->assertSuccessful();
        $this->artisan('events:send-reminders')->assertSuccessful();

        Notification::assertSentToTimes($early, EventNotification::class, 1);
        Notification::assertSentTo($early, EventNotification::class, fn (EventNotification $n): bool => $n->notice === EventNotice::Reminder);
        // Inscrit moins de 24 heures avant : sa confirmation suffit.
        Notification::assertNotSentTo($late, EventNotification::class);
        expect($event->fresh()->reminder_sent_at)->not->toBeNull()
            ->and($farAway->fresh()->reminder_sent_at)->toBeNull();
    });

    it('abandonne le rappel d\'un inscrit désinscrit avant l\'envoi', function (): void {
        $event = publishedEventOf();
        $attendee = projectOwner('particulier');
        $registration = registerTo($event, $attendee);
        $notification = new EventNotification($event, EventNotice::Reminder);

        expect($notification->shouldSend($attendee, 'mail'))->toBeTrue();
        $registration->delete();
        expect($notification->shouldSend($attendee, 'mail'))->toBeFalse();
    });
});

describe('événements terminés', function (): void {
    it('passe les événements terminés au statut "past" et clôt leur révision en attente', function (): void {
        Storage::fake('public');
        $event = Event::factory()->published()->ended()->create();
        $cover = Media::factory()->create(['user_id' => $event->user_id, 'purpose' => 'event_cover', 'attached_at' => now()]);
        $revision = new EventRevision;
        $revision->forceFill([
            'uuid' => (string) Str::uuid(), 'event_id' => $event->id, 'user_id' => $event->user_id,
            'status' => RevisionStatus::Pending, 'payload' => ['cover' => 'replace'], 'changed_fields' => ['cover'],
            'cover_media_id' => $cover->id, 'pending_key' => $event->id, 'submitted_at' => now(),
        ])->save();
        $upcoming = publishedEventOf();

        $this->artisan('events:close-past')->assertSuccessful();

        expect($event->fresh()->status)->toBe(EventStatus::Past)
            ->and($upcoming->fresh()->status)->toBe(EventStatus::Published)
            ->and($revision->fresh())->status->toBe(RevisionStatus::Superseded)->cover_media_id->toBeNull()
            ->and(Media::query()->whereKey($cover->id)->exists())->toBeFalse();
    });

    it('refuse une soumission dont la date de début est passée et prévient l\'organisateur', function (): void {
        $event = Event::factory()->pendingReview()->ongoing()->create(['user_id' => eventOrganizer()->id]);

        $this->artisan('events:close-past')->assertSuccessful();

        expect($event->fresh())->status->toBe(EventStatus::Rejected)->rejection_reason->toContain('date de début est passée');
        Notification::assertSentTo($event->user, EventNotification::class, fn (EventNotification $n): bool => $n->notice === EventNotice::EventRejected);
    });
});

describe('minimisation des données', function (): void {
    it('conserve par défaut 12 mois les inscriptions et les annonces, 6 mois les révisions', function (): void {
        expect(config('security.retention'))->toMatchArray([
            'event_registrations_months' => 12,
            'event_revisions_months' => 6,
            'event_announcements_months' => 12,
            'closed_events_months' => 12,
        ]);
    });

    it('supprime les données arrivées au bout de leur durée de conservation', function (): void {
        $old = Event::factory()->past()->create(['starts_at' => now()->subMonths(13), 'ends_at' => now()->subMonths(13)->addHours(2)]);
        $recent = Event::factory()->past()->create();
        $oldRegistration = registerTo($old);
        $recentRegistration = registerTo($recent);
        $oldAnnouncement = EventAnnouncement::query()->forceCreate([
            'uuid' => (string) Str::uuid(), 'event_id' => $old->id, 'user_id' => $old->user_id,
            'body' => 'Merci à tous pour votre participation à l\'atelier.', 'status' => AnnouncementStatus::Published,
        ]);
        $cancelledLongAgo = Event::factory()->published()->create(['status' => EventStatus::Cancelled, 'starts_at' => now()->subMonths(14), 'ends_at' => now()->subMonths(14)->addHour()]);
        $detachedRecent = Event::factory()->published()->create(['status' => EventStatus::Cancelled, 'user_id' => null, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);

        $this->travelTo(now()->subMonths(7));
        $decided = new EventRevision;
        $decided->forceFill([
            'uuid' => (string) Str::uuid(), 'event_id' => $recent->id, 'user_id' => $recent->user_id,
            'status' => RevisionStatus::Rejected, 'payload' => [], 'changed_fields' => ['title'], 'submitted_at' => now(),
        ])->save();
        $this->travelBack();

        $this->artisan('events:prune')->assertSuccessful();

        expect(EventRegistration::query()->whereKey($oldRegistration->id)->exists())->toBeFalse()
            ->and(EventRegistration::query()->whereKey($recentRegistration->id)->exists())->toBeTrue()
            ->and($old->fresh()->registrations_count)->toBe(1)
            ->and(EventAnnouncement::query()->whereKey($oldAnnouncement->id)->exists())->toBeFalse()
            ->and(EventRevision::query()->whereKey($decided->id)->exists())->toBeFalse()
            ->and(Event::query()->whereKey($cancelledLongAgo->id)->exists())->toBeFalse()
            ->and(Event::query()->whereKey($detachedRecent->id)->exists())->toBeTrue()
            ->and(Event::query()->whereKey($old->id)->exists())->toBeTrue();
    });
});

describe('suppression de compte', function (): void {
    it('supprime les inscriptions de l\'utilisateur et libère les places', function (): void {
        $event = publishedEventOf();
        $attendee = projectOwner('particulier');
        registerTo($event, $attendee);
        registerTo($event);

        app(DeleteAccount::class)->handle($attendee);

        expect(EventRegistration::query()->where('event_id', $event->id)->count())->toBe(1)
            ->and($event->fresh()->registrations_count)->toBe(1);
    });

    it('annule les événements à venir de l\'organisateur, prévient les inscrits et les détache de son compte', function (): void {
        Storage::fake('public');
        $organizer = eventOrganizer();
        $upcoming = publishedEventOf($organizer);
        $attendee = projectOwner('particulier');
        registerTo($upcoming, $attendee);
        $draft = Event::factory()->create(['user_id' => $organizer->id]);
        $pending = Event::factory()->pendingReview()->create(['user_id' => $organizer->id]);
        $past = Event::factory()->past()->create(['user_id' => $organizer->id]);
        registerTo($past);
        $cover = Media::factory()->create(['user_id' => $organizer->id, 'purpose' => 'event_cover', 'attached_at' => now()]);
        $upcoming->forceFill(['cover_media_id' => $cover->id])->save();

        app(DeleteAccount::class)->handle($organizer);

        expect($upcoming->fresh())
            ->status->toBe(EventStatus::Cancelled)
            ->cancelled_by->toBe(CancelledBy::System)
            ->user_id->toBeNull()
            ->organizer_name->toBeNull()
            ->cover_media_id->toBeNull()
            ->and(Event::query()->whereKey([$draft->id, $pending->id, $past->id])->count())->toBe(0);
        Notification::assertSentTo($attendee, EventNotification::class, fn (EventNotification $n): bool => $n->notice === EventNotice::Cancelled);

        // L'inscrit voit toujours l'annulation, sans aucune donnée de l'organisateur supprimé.
        $this->actingAs($attendee)->getJson("/api/v1/me/event-registrations/{$upcoming->slug}")
            ->assertOk()
            ->assertJsonPath('data.event.status', 'cancelled')
            ->assertJsonPath('data.event.organizer', ['kind' => 'named', 'name' => null, 'slug' => null, 'headline' => null, 'logo_url' => null]);
    });

    it('conserve la trace des décisions d\'un admin supprimé', function (): void {
        $admin = moderator();
        $event = Event::factory()->pendingReview()->create(['user_id' => eventOrganizer()->id]);
        app(ApproveEvent::class)->handle($admin, $event, 1);

        app(DeleteAccount::class)->handle($admin);

        expect(AdminAuditLog::query()->sole()->admin_id)->toBe($admin->id);
    });
});
