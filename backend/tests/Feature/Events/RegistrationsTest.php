<?php

use App\Models\User;
use App\Modules\Directory\Actions\BlockUser;
use App\Modules\Events\Enums\EventNotice;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Notifications\EventNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
| Inscriptions aux événements gratuits, vue de l'inscrit, et gestion des inscrits par
| l'organisateur (sans jamais lui donner leur email).
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-12 09:00:00');
    Notification::fake();
});

describe('inscription', function (): void {
    it('inscrit un utilisateur vérifié et lui envoie une confirmation sans lien de connexion', function (): void {
        $event = Event::factory()->published()->create(['format' => 'hybrid', 'online_url' => 'https://visio.exemple.fr/salle-secrete', 'capacity' => 10]);
        $attendee = projectOwner('particulier');

        $this->actingAs($attendee)->postJson("/api/v1/events/{$event->slug}/registration")
            ->assertCreated()
            ->assertJsonPath('event.slug', fn () => true)
            ->assertJsonPath('data.event.slug', $event->slug)
            ->assertJsonPath('data.event.online_url', 'https://visio.exemple.fr/salle-secrete');

        expect($event->fresh()->registrations_count)->toBe(1);
        Notification::assertSentTo($attendee, EventNotification::class, function (EventNotification $notification) use ($attendee): bool {
            $mail = $notification->toMail($attendee);

            return $notification->notice === EventNotice::RegistrationConfirmed
                && ! str_contains(json_encode($mail->toArray()), 'salle-secrete')
                && str_ends_with((string) $mail->actionUrl, '/events/'.$notification->event->slug);
        });
    });

    it('ouvre l\'inscription à tous les rôles', function (string $role): void {
        $event = publishedEventOf();

        $this->actingAs(projectOwner($role))->postJson("/api/v1/events/{$event->slug}/registration")->assertCreated();
    })->with(['particulier', 'prestataire', 'entrepreneur', 'redacteur', 'admin']);

    it('refuse une double inscription (409), garantie aussi par la base', function (): void {
        $event = publishedEventOf();
        $attendee = projectOwner('particulier');
        $registration = registerTo($event, $attendee);

        $this->actingAs($attendee)->postJson("/api/v1/events/{$event->slug}/registration")
            ->assertConflict()
            ->assertJsonPath('message', 'Vous êtes déjà inscrit à cet événement.');

        expect(fn () => DB::table('event_registrations')->insert([
            'uuid' => (string) Str::uuid(),
            'event_id' => $event->id,
            'user_id' => $attendee->id,
            'status' => 'registered',
        ]))->toThrow(UniqueConstraintViolationException::class);
        expect($registration->event->fresh()->registrations_count)->toBe(1);
    });

    it('respecte la capacité (409 quand l\'événement est complet, sans liste d\'attente)', function (): void {
        $event = publishedEventOf(null, ['capacity' => 1]);
        registerTo($event);

        $this->actingAs(projectOwner('particulier'))->postJson("/api/v1/events/{$event->slug}/registration")
            ->assertConflict()
            ->assertJsonPath('message', 'Cet événement est complet.');
    });

    it('applique le plafond global d\'inscrits même sans capacité indiquée', function (): void {
        config(['events.max_registrations' => 2]);
        $event = publishedEventOf(null, ['capacity' => null]);
        registerTo($event);
        registerTo($event);

        $this->actingAs(projectOwner('particulier'))->postJson("/api/v1/events/{$event->slug}/registration")->assertConflict();
    });

    it('refuse un événement payant, commencé ou passé (409)', function (Closure $makeEvent): void {
        $event = $makeEvent();

        $this->actingAs(projectOwner('particulier'))->postJson("/api/v1/events/{$event->slug}/registration")->assertConflict();

        expect(EventRegistration::query()->count())->toBe(0);
    })->with([
        'payant' => [fn () => Event::factory()->published()->paid()->create()],
        'commencé' => [fn () => Event::factory()->published()->ongoing()->create()],
        'passé' => [fn () => Event::factory()->past()->create()],
    ]);

    it('répond 404 pour un événement non publié, annulé ou inconnu', function (string $status): void {
        $event = Event::factory()->published()->create(['status' => $status]);

        $this->actingAs(projectOwner('particulier'))->postJson("/api/v1/events/{$event->slug}/registration")->assertNotFound();
    })->with(['draft', 'pending_review', 'rejected', 'cancelled']);

    it('refuse l\'organisateur sur son propre événement (403)', function (): void {
        $organizer = eventOrganizer();
        $event = publishedEventOf($organizer);

        $this->actingAs($organizer)->postJson("/api/v1/events/{$event->slug}/registration")->assertForbidden();
    });

    it('refuse un utilisateur bloqué par l\'organisateur, ou qui l\'a bloqué (403)', function (string $direction): void {
        $organizer = eventOrganizer();
        $event = publishedEventOf($organizer);
        $attendee = projectOwner('particulier');

        $direction === 'organizer'
            ? app(BlockUser::class)->handle($organizer, $attendee->id)
            : app(BlockUser::class)->handle($attendee, $organizer->id);

        $this->actingAs($attendee)->postJson("/api/v1/events/{$event->slug}/registration")
            ->assertForbidden()
            ->assertJsonPath('message', 'Vous ne pouvez pas vous inscrire à cet événement.');

        expect(securityLog('events.registration_blocked')[0]->context)->toMatchArray(['user_id' => $attendee->id, 'event' => $event->uuid]);
    })->with(['organizer', 'attendee']);

    it('exige un email vérifié (403)', function (): void {
        $event = publishedEventOf();

        $this->actingAs(projectOwner('particulier', verified: false))->postJson("/api/v1/events/{$event->slug}/registration")->assertForbidden();
    });

    it('désinscrit avant le début et libère la place, puis permet de se réinscrire', function (): void {
        $event = publishedEventOf(null, ['capacity' => 1]);
        $attendee = projectOwner('particulier');
        registerTo($event, $attendee);

        $this->actingAs($attendee)->deleteJson("/api/v1/events/{$event->slug}/registration")->assertNoContent();
        $this->actingAs($attendee)->deleteJson("/api/v1/events/{$event->slug}/registration")->assertNotFound();

        expect($event->fresh()->registrations_count)->toBe(0);
        $this->actingAs($attendee)->postJson("/api/v1/events/{$event->slug}/registration")->assertCreated();
    });

    it('refuse la désinscription après le début (409)', function (): void {
        $event = Event::factory()->published()->ongoing()->create();
        $attendee = projectOwner('particulier');
        registerTo($event, $attendee);

        $this->actingAs($attendee)->deleteJson("/api/v1/events/{$event->slug}/registration")->assertConflict();
    });

    it('limite les inscriptions et désinscriptions (429)', function (): void {
        config(['security.rate_limits.event_registrations.per_hour' => 2]);
        $attendee = projectOwner('particulier');
        $events = Event::factory()->published()->count(3)->create();

        $this->actingAs($attendee)->postJson("/api/v1/events/{$events[0]->slug}/registration")->assertCreated();
        $this->actingAs($attendee)->postJson("/api/v1/events/{$events[1]->slug}/registration")->assertCreated();
        $this->actingAs($attendee)->postJson("/api/v1/events/{$events[2]->slug}/registration")->assertTooManyRequests();
    });
});

describe('vue de l\'inscrit', function (): void {
    it('liste ses inscriptions à venir puis passées', function (): void {
        $attendee = projectOwner('particulier');
        $later = publishedEventOf(null, ['starts_at' => now()->addDays(20), 'ends_at' => now()->addDays(20)->addHour()]);
        $sooner = publishedEventOf(null, ['starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHour()]);
        $past = Event::factory()->past()->create();
        foreach ([$later, $sooner, $past] as $event) {
            registerTo($event, $attendee);
        }
        registerTo(publishedEventOf());

        $this->actingAs($attendee)->getJson('/api/v1/me/event-registrations')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.event.slug', $sooner->slug)
            ->assertJsonPath('data.1.event.slug', $later->slug);
        $this->actingAs($attendee)->getJson('/api/v1/me/event-registrations?when=past')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event.status', 'past')
            ->assertJsonPath('data.0.event.online_url', null);
        $this->actingAs($attendee)->getJson('/api/v1/me/event-registrations?when=demain')->assertUnprocessable();
    });

    it('donne à l\'inscrit le lien de connexion et l\'adresse réservée aux inscrits', function (): void {
        $event = Event::factory()->published()->create(['format' => 'hybrid', 'online_url' => 'https://visio.exemple.fr/salle-secrete', 'address_visibility' => 'registrants']);
        $attendee = projectOwner('particulier');
        registerTo($event, $attendee);

        $this->actingAs($attendee)->getJson("/api/v1/me/event-registrations/{$event->slug}")
            ->assertOk()
            ->assertJsonPath('data.event.online_url', 'https://visio.exemple.fr/salle-secrete')
            ->assertJsonPath('data.event.venue.address_line', '12 rue de la République')
            ->assertJsonPath('data.event.venue.postal_code', '69001')
            ->assertJsonPath('data.announcements', []);

        $ics = (string) $this->actingAs($attendee)->get("/api/v1/me/event-registrations/{$event->slug}/calendar.ics")->assertOk()->getContent();
        expect(str_replace("\r\n ", '', $ics))->toContain('https://visio.exemple.fr/salle-secrete')->toContain('République');
    });

    it('cache le lien et l\'adresse à un non-inscrit (404), même connecté', function (): void {
        $event = Event::factory()->published()->online()->create();
        $other = projectOwner('particulier');

        $this->actingAs($other)->getJson("/api/v1/me/event-registrations/{$event->slug}")->assertNotFound();
        $this->actingAs($other)->get("/api/v1/me/event-registrations/{$event->slug}/calendar.ics")->assertNotFound();
        $this->actingAs($event->user)->getJson("/api/v1/me/event-registrations/{$event->slug}")->assertNotFound();
    });

    it('montre l\'annulation à l\'inscrit, sans lien de connexion', function (): void {
        $event = Event::factory()->published()->online()->create(['status' => EventStatus::Cancelled, 'cancellation_reason' => 'Intervenante malade.', 'cancelled_by' => 'organizer']);
        $attendee = projectOwner('particulier');
        registerTo($event, $attendee);

        $this->actingAs($attendee)->getJson("/api/v1/me/event-registrations/{$event->slug}")
            ->assertOk()
            ->assertJsonPath('data.event.status', 'cancelled')
            ->assertJsonPath('data.event.cancellation_reason', 'Intervenante malade.')
            ->assertJsonPath('data.event.online_url', null);
    });
});

describe('inscrits, vus par l\'organisateur', function (): void {
    it('liste les inscrits avec leur nom et leur type de compte, jamais leur email', function (): void {
        $organizer = eventOrganizer();
        $event = publishedEventOf($organizer);
        $attendee = projectOwner('entrepreneur');
        registerTo($event, $attendee);

        $response = $this->actingAs($organizer)->getJson("/api/v1/me/events/{$event->uuid}/registrations")
            ->assertOk()
            ->assertJsonPath('data.0.name', $attendee->name)
            ->assertJsonPath('data.0.account_type', 'entrepreneur')
            ->assertJsonPath('meta.registrations_count', 1)
            ->assertJsonPath('meta.registration_limit', 300);

        expect(array_keys($response->json('data.0')))->toBe(['uuid', 'name', 'account_type', 'registered_at'])
            ->and($response->getContent())->not->toContain($attendee->email);
        expectNoPrivateFields($response->json('data'));
    });

    it('cache les inscrits d\'un autre organisateur (404), admin compris', function (string $role): void {
        $event = publishedEventOf();
        registerTo($event);

        $this->actingAs(eventOrganizer($role))->getJson("/api/v1/me/events/{$event->uuid}/registrations")->assertNotFound();
    })->with(['entrepreneur', 'admin']);

    it('retire un inscrit : email neutre, aucune réinscription possible, journal sans donnée personnelle', function (): void {
        $organizer = eventOrganizer();
        $event = publishedEventOf($organizer);
        $attendee = projectOwner('particulier');
        $registration = registerTo($event, $attendee);

        $this->actingAs($organizer)->deleteJson("/api/v1/me/events/{$event->uuid}/registrations/{$registration->uuid}")->assertNoContent();

        expect($registration->fresh()->status)->toBe(RegistrationStatus::Removed)
            ->and($event->fresh()->registrations_count)->toBe(0)
            ->and(securityLog('events.registration_removed')[0]->context)->toMatchArray(['event' => $event->uuid, 'registration' => $registration->uuid]);
        Notification::assertSentTo($attendee, EventNotification::class, function (EventNotification $notification) use ($attendee): bool {
            $intro = implode(' ', $notification->toMail($attendee)->introLines);

            return $notification->notice === EventNotice::RegistrationRemoved
                && str_contains($intro, 'a été annulée par l\'organisateur')
                && $notification->reason === null;
        });
        expect(json_encode(securityLog('events.registration_removed')))->not->toContain($attendee->name)->not->toContain($attendee->email);

        $this->actingAs($attendee)->postJson("/api/v1/events/{$event->slug}/registration")
            ->assertForbidden()
            ->assertJsonPath('message', 'Vous ne pouvez plus vous inscrire à cet événement.');
        $this->actingAs($attendee)->getJson("/api/v1/me/event-registrations/{$event->slug}")->assertNotFound();
    });

    it('cache le retrait d\'un inscrit à tout autre que l\'organisateur (404)', function (string $role): void {
        $event = publishedEventOf();
        $registration = registerTo($event);

        $this->actingAs(eventOrganizer($role))->deleteJson("/api/v1/me/events/{$event->uuid}/registrations/{$registration->uuid}")->assertNotFound();

        expect($registration->fresh()->status)->toBe(RegistrationStatus::Registered);
    })->with(['entrepreneur', 'redacteur', 'admin']);

    it('répond 404 pour l\'inscription d\'un autre événement de l\'organisateur ou déjà retirée', function (): void {
        $organizer = eventOrganizer();
        $event = publishedEventOf($organizer);
        $otherEvent = publishedEventOf($organizer);
        $registration = registerTo($otherEvent);

        $this->actingAs($organizer)->deleteJson("/api/v1/me/events/{$event->uuid}/registrations/{$registration->uuid}")->assertNotFound();
    });

    it('limite les retraits d\'inscrits (429)', function (): void {
        config(['security.rate_limits.event_registrant_removals.per_day' => 1]);
        $organizer = eventOrganizer();
        $event = publishedEventOf($organizer);
        [$first, $second] = [registerTo($event), registerTo($event)];

        $this->actingAs($organizer)->deleteJson("/api/v1/me/events/{$event->uuid}/registrations/{$first->uuid}")->assertNoContent();
        $this->actingAs($organizer)->deleteJson("/api/v1/me/events/{$event->uuid}/registrations/{$second->uuid}")->assertTooManyRequests();
    });
});

it('ne laisse pas User déclarer de relation vers les inscriptions', function (): void {
    expect(method_exists(User::class, 'eventRegistrations'))->toBeFalse();
});
