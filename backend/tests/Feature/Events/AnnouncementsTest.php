<?php

use App\Models\AdminAuditLog;
use App\Modules\Events\Enums\AnnouncementStatus;
use App\Modules\Events\Enums\EventNotice;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAnnouncement;
use App\Modules\Events\Notifications\EventNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
| Annonces de l'organisateur à ses inscrits : envoyées par la plateforme, texte lu sur la page
| de l'événement (jamais dans l'email), validées par un admin si elles contiennent un lien.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-12 09:00:00');
    Notification::fake();
});

const ANNOUNCEMENT = 'Rappel : pensez à apporter vos dernières factures pour les exercices pratiques.';

it('publie tout de suite une annonce sans lien et prévient les inscrits sans le texte', function (): void {
    $organizer = eventOrganizer();
    $event = publishedEventOf($organizer);
    $attendee = projectOwner('particulier');
    registerTo($event, $attendee);

    $this->actingAs($organizer)->postJson("/api/v1/me/events/{$event->uuid}/announcements", ['body' => ANNOUNCEMENT])
        ->assertCreated()
        ->assertJsonPath('data.status', 'published')
        ->assertJsonPath('data.recipients_count', 1);

    Notification::assertSentTo($attendee, EventNotification::class, function (EventNotification $notification) use ($attendee): bool {
        return $notification->notice === EventNotice::AnnouncementPublished
            && ! str_contains(json_encode($notification->toMail($attendee)->toArray()), 'factures');
    });

    $this->actingAs($attendee)->getJson("/api/v1/me/event-registrations/{$event->slug}")
        ->assertJsonPath('data.announcements.0.body', ANNOUNCEMENT);
    expect(json_encode(securityLog()))->not->toContain('factures');
});

it('soumet à la validation d\'un admin une annonce qui contient un lien', function (): void {
    $organizer = eventOrganizer();
    $event = publishedEventOf($organizer);
    $attendee = projectOwner('particulier');
    registerTo($event, $attendee);
    $body = 'Le support de l\'atelier est disponible sur docs.exemple.fr dès maintenant.';

    $uuid = $this->actingAs($organizer)->postJson("/api/v1/me/events/{$event->uuid}/announcements", ['body' => $body])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending_review')
        ->assertJsonPath('data.has_links', true)
        ->json('data.uuid');

    Notification::assertNothingSent();
    $this->actingAs($attendee)->getJson("/api/v1/me/event-registrations/{$event->slug}")->assertJsonPath('data.announcements', []);

    asModerator()->getJson('/api/v1/admin/events/queue?kind=announcement')->assertJsonPath('data.0.uuid', $uuid);
    asModerator()->getJson("/api/v1/admin/event-announcements/{$uuid}")
        ->assertOk()
        ->assertJsonPath('data.body', $body)
        ->assertJsonPath('data.domains.0.host', 'docs.exemple.fr');

    asModerator()->postJson("/api/v1/admin/event-announcements/{$uuid}/approve", ['version' => 1])->assertUnprocessable();
    asModerator()->postJson("/api/v1/admin/event-announcements/{$uuid}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'published')
        ->assertJsonPath('data.recipients_count', 1);

    Notification::assertSentTo($attendee, EventNotification::class, fn (EventNotification $n): bool => $n->notice === EventNotice::AnnouncementPublished);
    Notification::assertSentTo($organizer, EventNotification::class, fn (EventNotification $n): bool => $n->notice === EventNotice::AnnouncementApproved);
    expect(AdminAuditLog::query()->sole()->action)->toBe('events.announcement.approved');
});

it('refuse une annonce avec un motif communiqué à l\'organisateur', function (): void {
    $organizer = eventOrganizer();
    $event = publishedEventOf($organizer);
    registerTo($event);
    $announcement = $this->actingAs($organizer)->postJson("/api/v1/me/events/{$event->uuid}/announcements", ['body' => 'Offre exceptionnelle pour les inscrits sur promo-exemple.fr, profitez-en !'])
        ->json('data.uuid');
    $reason = 'Lien commercial sans rapport avec l\'événement.';

    asModerator()->postJson("/api/v1/admin/event-announcements/{$announcement}/reject", ['reason' => $reason])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');

    expect(EventAnnouncement::query()->sole()->status)->toBe(AnnouncementStatus::Rejected);
    Notification::assertSentTo($organizer, EventNotification::class, fn (EventNotification $n): bool => $n->notice === EventNotice::AnnouncementRejected && $n->reason === $reason);
    $this->actingAs($organizer)->getJson("/api/v1/me/events/{$event->uuid}/announcements")
        ->assertJsonPath('data.0.rejection_reason', $reason);
});

it('limite à une annonce par 24 heures et 5 par événement (409)', function (): void {
    $organizer = eventOrganizer();
    $event = publishedEventOf($organizer, ['starts_at' => now()->addDays(20), 'ends_at' => now()->addDays(20)->addHours(2)]);

    $this->actingAs($organizer)->postJson("/api/v1/me/events/{$event->uuid}/announcements", ['body' => ANNOUNCEMENT])->assertCreated();
    $this->actingAs($organizer)->postJson("/api/v1/me/events/{$event->uuid}/announcements", ['body' => ANNOUNCEMENT])->assertConflict();

    for ($day = 1; $day <= 4; $day++) {
        $this->travel(25)->hours();
        cache()->flush();
        $this->actingAs($organizer)->postJson("/api/v1/me/events/{$event->uuid}/announcements", ['body' => ANNOUNCEMENT])->assertCreated();
    }

    $this->travel(25)->hours();
    cache()->flush();
    $this->actingAs($organizer)->postJson("/api/v1/me/events/{$event->uuid}/announcements", ['body' => ANNOUNCEMENT])
        ->assertConflict()
        ->assertJsonPath('message', 'Vous avez déjà envoyé 5 annonces pour cet événement : c\'est le maximum.');
});

it('limite les annonces par organisateur (429)', function (): void {
    $organizer = eventOrganizer();
    $events = collect(range(1, 4))->map(fn () => publishedEventOf($organizer));

    foreach ($events->take(3) as $event) {
        $this->actingAs($organizer)->postJson("/api/v1/me/events/{$event->uuid}/announcements", ['body' => ANNOUNCEMENT])->assertCreated();
    }

    $this->actingAs($organizer)->postJson("/api/v1/me/events/{$events[3]->uuid}/announcements", ['body' => ANNOUNCEMENT])->assertTooManyRequests();
});

it('refuse un texte invalide ou malveillant (422)', function (string $body): void {
    $organizer = eventOrganizer();
    $event = publishedEventOf($organizer);

    $this->actingAs($organizer)->postJson("/api/v1/me/events/{$event->uuid}/announcements", ['body' => $body])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['body']);
})->with([
    'trop court' => 'Merci !',
    'HTML' => 'Rendez-vous <a href="https://exemple.fr">ici</a> pour le programme complet.',
    'raccourcisseur' => 'Le programme complet est disponible sur bit.ly/programme-atelier.',
    'caractère invisible' => "Rappel important\u{202E} pour tous les inscrits de l'atelier.",
]);

it('refuse une annonce pour un événement annulé ou passé (409)', function (string $state): void {
    $organizer = eventOrganizer();
    $event = Event::factory()->{$state}()->create(['user_id' => $organizer->id]);

    $this->actingAs($organizer)->postJson("/api/v1/me/events/{$event->uuid}/announcements", ['body' => ANNOUNCEMENT])->assertConflict();
})->with(['past', 'pendingReview']);

it('cache les annonces d\'un autre organisateur (404), admin compris', function (string $role): void {
    $event = publishedEventOf();

    $this->actingAs(eventOrganizer($role))->postJson("/api/v1/me/events/{$event->uuid}/announcements", ['body' => ANNOUNCEMENT])->assertNotFound();
    $this->actingAs(eventOrganizer($role))->getJson("/api/v1/me/events/{$event->uuid}/announcements")->assertNotFound();
})->with(['entrepreneur', 'admin']);
