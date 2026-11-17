<?php

use App\Models\User;
use App\Modules\Compliance\Enums\DeadlineSource;
use App\Modules\Compliance\Enums\DeadlineStatus;
use App\Modules\Compliance\Models\Deadline;
use App\Modules\Events\Enums\EventStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/*
| Agenda unifié et abonnement unique (lot 4) : rendez-vous, événements suivis ou organisés,
| échéances du suivi administratif. L'abonnement ne contient que les titres et les dates.
|
| Horloge : lundi 22 mars 2027, 08:00 à Paris.
*/

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2027-03-22 07:00:00'));
});

/**
 * Prestataire avec un rendez-vous confirmé (mardi), un rendez-vous en attente (mercredi), une
 * inscription à un événement (jeudi), un événement organisé (vendredi) et une échéance
 * (samedi) : [prestataire, client].
 *
 * @return array{0: User, 1: User}
 */
function busyProvider(): array
{
    [$client, $provider, $matchRequest] = appointmentRelation();
    weeklyAvailability($provider);
    $confirmed = appointmentTypeOf($provider, ['title' => 'Bilan de projet', 'auto_confirm' => true]);
    $pending = appointmentTypeOf($provider, ['title' => 'Visite', 'position' => 1]);

    foreach ([[$confirmed, '2027-03-23T08:00:00Z', 'Mon code de porte est 1234.'], [$pending, '2027-03-24T08:00:00Z', null]] as [$type, $startsAt, $note]) {
        test()->actingAs($client)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/appointments", ['type' => $type->uuid, 'starts_at' => $startsAt, 'note' => $note])->assertCreated();
    }

    $event = publishedEventOf(eventOrganizer(), ['title' => 'Atelier facturation', 'starts_at' => '2027-03-25 17:00:00', 'ends_at' => '2027-03-25 19:00:00']);
    registerTo($event, $provider);
    $cancelled = publishedEventOf(eventOrganizer(), ['title' => 'Salon annulé', 'starts_at' => '2027-03-25 09:00:00', 'ends_at' => '2027-03-25 10:00:00']);
    registerTo($cancelled, $provider);
    $cancelled->forceFill(['status' => EventStatus::Cancelled])->save();
    publishedEventOf($provider, ['title' => 'Ma conférence', 'starts_at' => '2027-03-26 17:00:00', 'ends_at' => '2027-03-26 18:00:00']);

    verifiedLegalForm();
    $company = complianceCompanyOf($provider);
    Deadline::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'company_id' => $company->id,
        'source' => DeadlineSource::Custom,
        'title' => 'Renouveler mon assurance',
        'due_on' => '2027-03-27',
        'status' => DeadlineStatus::Todo,
    ]);

    return [$provider, $client];
}

it('rassemble les rendez-vous, les événements et les échéances de l\'utilisateur, triés par début', function (): void {
    [$provider] = busyProvider();

    $response = $this->actingAs($provider)->getJson('/api/v1/me/agenda?from=2027-03-22&to=2027-03-28')->assertOk();
    $items = collect($response->json('data'));

    expect($items->map(fn (array $item): string => $item['kind'].':'.$item['title'])->all())->toBe([
        'appointment:Rendez-vous : Bilan de projet',
        'appointment:Rendez-vous : Visite',
        'event_registration:Salon annulé',
        'event_registration:Atelier facturation',
        'event_organized:Ma conférence',
        'deadline:Renouveler mon assurance',
    ])
        ->and($items->firstWhere('kind', 'deadline'))->toMatchArray(['all_day' => true, 'date' => '2027-03-27', 'local' => null])
        ->and($items->first())->toMatchArray(['role' => 'provider', 'status' => 'confirmed', 'timezone' => 'Europe/Paris'])
        ->and($items->first()['local'])->toBe(['starts_at' => '2027-03-23T09:00', 'ends_at' => '2027-03-23T09:30', 'offset' => '+01:00']);

    $response->assertJsonPath('disclaimers.compliance.version', fn (mixed $version): bool => is_int($version));
    expect(json_encode($response->json()))->not->toContain('1234')->not->toContain('visio.exemple.fr');
});

it('filtre par source et par période, dans la limite de 92 jours', function (): void {
    [$provider] = busyProvider();

    $this->actingAs($provider)->getJson('/api/v1/me/agenda?from=2027-03-22&to=2027-03-28&sources[]=events')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('disclaimers', []);
    $this->actingAs($provider)->getJson('/api/v1/me/agenda?from=2027-03-24&to=2027-03-24')->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($provider)->getJson('/api/v1/me/agenda?from=2027-03-22&to=2027-07-01')->assertUnprocessable()->assertJsonValidationErrors('to');
    $this->actingAs($provider)->getJson('/api/v1/me/agenda?sources[]=projects')->assertUnprocessable()->assertJsonValidationErrors('sources.0');
});

it('ne montre que les éléments de l\'utilisateur connecté', function (): void {
    [, $client] = busyProvider();

    $items = collect($this->actingAs($client)->getJson('/api/v1/me/agenda?from=2027-03-22&to=2027-03-28')->assertOk()->json('data'));

    expect($items->pluck('kind')->all())->toBe(['appointment', 'appointment'])
        ->and($items->pluck('role')->unique()->all())->toBe(['client']);
    $this->actingAs(projectOwner('particulier'))->getJson('/api/v1/me/agenda?from=2027-03-22&to=2027-03-28')->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs(projectOwner('particulier', verified: false))->getJson('/api/v1/me/agenda')->assertForbidden();
});

describe('abonnement unique', function (): void {
    it('ne contient que les titres et les dates des éléments confirmés ou publiés', function (): void {
        [$provider, $client] = busyProvider();

        $url = $this->actingAs($provider)->postJson('/api/v1/me/agenda/feed')
            ->assertCreated()
            ->assertJsonPath('data.active', true)
            ->json('data.url');
        $this->actingAs($provider)->getJson('/api/v1/me/agenda/feed')->assertOk()->assertJsonMissingPath('data.url');

        $response = $this->get(parse_url($url, PHP_URL_PATH))->assertOk();
        $ics = (string) $response->getContent();

        expect($response->headers->get('Content-Type'))->toStartWith('text/calendar')
            ->and($ics)->toContain('SUMMARY:Rendez-vous : Bilan de projet')
            ->and($ics)->toContain('DTSTART:20270323T080000Z')
            ->and($ics)->toContain('SUMMARY:Atelier facturation')
            ->and($ics)->toContain('SUMMARY:Ma conférence')
            ->and($ics)->toContain('DTSTART;VALUE=DATE:20270327')
            ->and($ics)->toContain('SUMMARY:Renouveler mon assurance')
            ->and($ics)->not->toContain('Visite')
            ->and($ics)->not->toContain('Salon annulé')
            ->and($ics)->not->toContain('visio.exemple.fr')
            ->and($ics)->not->toContain('1234')
            ->and($ics)->not->toContain($client->name)
            ->and($ics)->not->toContain('LOCATION')
            ->and($ics)->not->toContain('URL:');

        foreach (securityLog() as $record) {
            expect(json_encode($record->context))->not->toContain(substr((string) $url, -40));
        }
    });

    it('remplace l\'ancienne adresse à la régénération, et la révoque', function (): void {
        $user = projectOwner('particulier');
        $first = $this->actingAs($user)->postJson('/api/v1/me/agenda/feed')->json('data.url');
        $second = $this->actingAs($user)->postJson('/api/v1/me/agenda/feed')->json('data.url');

        $this->get(parse_url($first, PHP_URL_PATH))->assertNotFound();
        $this->get(parse_url($second, PHP_URL_PATH))->assertOk();

        $this->actingAs($user)->deleteJson('/api/v1/me/agenda/feed')->assertNoContent();
        $this->get(parse_url($second, PHP_URL_PATH))->assertNotFound();
        $this->actingAs($user)->deleteJson('/api/v1/me/agenda/feed')->assertNotFound();

        expect(securityLog('agenda.feed_created'))->toHaveCount(1)
            ->and(securityLog('agenda.feed_regenerated'))->toHaveCount(1)
            ->and(securityLog('agenda.feed_revoked'))->toHaveCount(1);
    });

    it('refuse un jeton inconnu et ne le journalise qu\'une fois par heure et par IP', function (): void {
        $this->get('/api/v1/agenda/'.str_repeat('a', 56).'.ics')->assertNotFound();
        $this->get('/api/v1/agenda/'.str_repeat('b', 56).'.ics')->assertNotFound();
        $this->get('/api/v1/agenda/'.str_repeat('a', 12).'.ics')->assertNotFound();

        expect(securityLog('agenda.feed_invalid'))->toHaveCount(1);
    });

    it('limite les accès à un abonnement (429)', function (): void {
        config(['security.rate_limits.agenda_feed.per_hour_per_feed' => 2]);
        $url = $this->actingAs(projectOwner('particulier'))->postJson('/api/v1/me/agenda/feed')->json('data.url');
        $path = parse_url($url, PHP_URL_PATH);

        $this->get($path)->assertOk();
        $this->get($path)->assertOk();
        $this->get($path)->assertTooManyRequests();
    });
});
