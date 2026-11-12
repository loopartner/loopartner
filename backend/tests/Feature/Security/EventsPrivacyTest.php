<?php

use App\Modules\Events\Models\Event;
use App\Modules\Events\Notifications\EventNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
| Module Events : confidentialité (OWASP API3). Le lien de connexion, l'email des inscrits et
| le contenu saisi ne sortent jamais là où ils ne doivent pas : réponses publiques, emails,
| journal security.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-12 09:00:00');
    Notification::fake();
});

const SECRET_LINK = 'https://visio.exemple.fr/salle-tres-secrete';

it('ne laisse sortir le lien de connexion, les emails et le contenu saisi ni dans les emails, ni dans le journal, ni dans les réponses publiques', function (): void {
    $organizer = eventOrganizer();
    $attendee = projectOwner('particulier');

    // Parcours complet : création, soumission, validation, inscription, révision, annonce, annulation.
    $uuid = $this->actingAs($organizer)->postJson('/api/v1/me/events', eventPayload([
        'format' => 'hybrid',
        'online_url' => SECRET_LINK,
        'description' => 'Description confidentielle de l\'atelier pour vérifier le journal de sécurité.',
    ]))->assertCreated()->json('data.uuid');
    $this->actingAs($organizer)->postJson("/api/v1/me/events/{$uuid}/submit")->assertStatus(202);
    asModerator()->postJson("/api/v1/admin/events/{$uuid}/approve", ['version' => 1])->assertOk();
    $slug = Event::query()->where('uuid', $uuid)->value('slug');

    $this->actingAs($attendee)->postJson("/api/v1/events/{$slug}/registration")->assertCreated();
    $revision = $this->actingAs($organizer)->patchJson("/api/v1/me/events/{$uuid}", ['online_url' => SECRET_LINK.'-v2'])
        ->assertStatus(202)
        ->json('data.pending_revision.uuid');
    asModerator()->postJson("/api/v1/admin/event-revisions/{$revision}/approve", ['version' => 1])->assertOk();
    $this->actingAs($organizer)->postJson("/api/v1/me/events/{$uuid}/announcements", ['body' => 'Annonce confidentielle pour les inscrits de l\'atelier.'])->assertCreated();

    // Réponses publiques.
    foreach (['/api/v1/events', "/api/v1/events/{$slug}", "/api/v1/events/{$slug}/calendar.ics"] as $uri) {
        $content = (string) $this->get($uri, ['Accept' => 'application/json'])->assertOk()->getContent();

        expect($content)->not->toContain('salle-tres-secrete')
            ->not->toContain($attendee->email)
            ->not->toContain($organizer->email)
            ->not->toContain($uuid);
    }

    $this->actingAs($organizer)->postJson("/api/v1/me/events/{$uuid}/cancel", ['reason' => 'Motif d\'annulation confidentiel.'])->assertOk();

    // Emails : jamais le lien de connexion, le texte d'une annonce ni le motif d'annulation.
    $mails = [];
    foreach ([$attendee, $organizer] as $recipient) {
        Notification::assertSentTo($recipient, EventNotification::class, function (EventNotification $notification) use ($recipient, &$mails): bool {
            $mails[] = json_encode($notification->toMail($recipient)->toArray(), JSON_UNESCAPED_UNICODE);

            return true;
        });
    }

    expect(implode("\n", $mails))
        ->not->toContain('salle-tres-secrete')
        ->not->toContain('Annonce confidentielle')
        ->not->toContain('Motif d\'annulation confidentiel')
        ->not->toContain($attendee->email)
        ->not->toContain($attendee->name);

    // Journal security : ni description, ni lien, ni texte d'annonce, ni motif, ni email, ni nom.
    $log = json_encode(securityLog(), JSON_UNESCAPED_UNICODE);

    expect($log)
        ->not->toContain('salle-tres-secrete')
        ->not->toContain('Description confidentielle')
        ->not->toContain('Annonce confidentielle')
        ->not->toContain('Motif d\'annulation')
        ->not->toContain($attendee->email)
        ->not->toContain($attendee->name)
        ->not->toContain($organizer->email)
        ->and(collect(securityLog())->pluck('message')->unique()->values()->all())->toContain(
            'events.submitted', 'events.approved', 'events.revision_submitted', 'events.revision_approved',
            'events.announcement_published', 'events.cancelled',
        );
});

it('n\'expose aux organisateurs que le nom et le type de compte des inscrits', function (): void {
    $organizer = eventOrganizer();
    $event = publishedEventOf($organizer);
    $attendee = projectOwner('particulier', verified: true);
    $attendee->forceFill(['country' => 'BE', 'timezone' => 'Europe/Brussels'])->save();
    registerTo($event, $attendee);

    $content = (string) $this->actingAs($organizer)->getJson("/api/v1/me/events/{$event->uuid}/registrations")->assertOk()->getContent();

    expect($content)->not->toContain($attendee->email)
        ->not->toContain('Europe/Brussels')
        ->not->toContain('"BE"')
        ->not->toContain('"id"')
        ->not->toContain('user_id');
});
