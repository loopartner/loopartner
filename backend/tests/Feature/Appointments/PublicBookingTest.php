<?php

use App\Models\User;
use App\Modules\Accounts\Models\PremiumGrant;
use App\Modules\Appointments\Enums\AppointmentNotice;
use App\Modules\Appointments\Models\AppointmentType;
use App\Modules\Appointments\Notifications\AppointmentNotification;
use App\Modules\Directory\Enums\MatchRequestStatus;
use App\Modules\Directory\Enums\MatchRequestType;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Models\UserBlock;
use App\Modules\Directory\Notifications\MatchingNotification;
use App\Support\Moderation\ModerationTargets;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

/*
| Page de réservation publique d'un prestataire Premium (décisions 52 et 53) : types publics et
| créneaux pour tous, réservation avec un motif et une demande de contact, nom du client révélé
| seulement à la confirmation.
|
| Horloge : lundi 22 mars 2027, 08:00 à Paris ; plages de 09:00 à 12:00 tous les jours.
*/

const PUBLIC_NOTE = 'Bonjour, je souhaite un premier échange sur la refonte de mon site.';

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2027-03-22 07:00:00'));
});

/**
 * Prestataire Premium dont la page publique est ouverte, avec un type public et un type
 * réservé aux relations : [prestataire, slug, type public, type de relation].
 *
 * @return array{0: User, 1: string, 2: AppointmentType, 3: AppointmentType}
 */
function publicBookingPage(): array
{
    $provider = appointmentProvider(['public_page_enabled' => true]);
    makePremium($provider);
    weeklyAvailability($provider);

    return [
        $provider,
        providerSlug($provider),
        appointmentTypeOf($provider, ['title' => 'Premier échange', 'visibility' => 'public', 'position' => 1]),
        appointmentTypeOf($provider, ['title' => 'Suivi de chantier', 'visibility' => 'relation', 'position' => 2]),
    ];
}

function bookOnPage(User $client, string $slug, string $type, array $extra = []): TestResponse
{
    return test()->actingAs($client)->postJson("/api/v1/providers/{$slug}/appointments", ['type' => $type, 'starts_at' => '2027-03-22T08:00:00Z', 'note' => PUBLIC_NOTE, ...$extra]);
}

it('montre à tous les types publics, les créneaux et l\'information avant réservation', function (): void {
    [, $slug, $public] = publicBookingPage();

    $this->getJson("/api/v1/providers/{$slug}/booking?type={$public->uuid}&from=2027-03-22&to=2027-03-22")
        ->assertOk()
        ->assertJsonCount(1, 'data.types')
        ->assertJsonPath('data.types.0.title', 'Premier échange')
        ->assertJsonMissingPath('data.types.0.location_details')
        ->assertJsonPath('data.provider.slug', $slug)
        ->assertJsonCount(6, 'data.slots')
        ->assertJsonPath('meta.notices.public_booking_privacy', 'Votre motif et votre type de compte sont transmis au prestataire avec une demande de mise en relation. Votre nom ne lui est communiqué que s\'il confirme le rendez-vous.');
});

it('ferme la page sans Premium, page désactivée ou profil masqué', function (string $case): void {
    [$provider, $slug, $public] = publicBookingPage();

    match ($case) {
        'sans Premium' => PremiumGrant::query()->update(['revoked_at' => now()]),
        'page désactivée' => appointmentCalendarOf($provider)->forceFill(['public_page_enabled' => false])->save(),
        'réservations fermées' => appointmentCalendarOf($provider)->forceFill(['is_enabled' => false])->save(),
        'profil masqué' => restrictContent('provider_profile', $slug),
    };

    $this->getJson("/api/v1/providers/{$slug}/booking")->assertNotFound();
    $this->actingAs(projectOwner('particulier'))->postJson("/api/v1/providers/{$slug}/appointments", [])->assertNotFound();
})->with(['sans Premium', 'page désactivée', 'réservations fermées', 'profil masqué']);

it('crée une demande de rendez-vous et une demande de contact, sans révéler le nom avant la confirmation', function (): void {
    [$provider, $slug, $public] = publicBookingPage();
    $client = projectOwner('entrepreneur');

    $uuid = bookOnPage($client, $slug, $public->uuid)
        ->assertCreated()
        ->assertJsonPath('data.status', 'requested')
        ->assertJsonPath('data.origin', 'public_page')
        ->json('data.uuid');

    $contact = MatchRequest::query()->sole();
    expect($contact->type)->toBe(MatchRequestType::Contact)
        ->and($contact->status)->toBe(MatchRequestStatus::Pending)
        ->and($contact->message)->toBe(PUBLIC_NOTE);
    Notification::assertSentTo($provider, AppointmentNotification::class, fn (AppointmentNotification $notification): bool => $notification->notice === AppointmentNotice::RequestReceived);
    Notification::assertNotSentTo($provider, MatchingNotification::class);

    $this->actingAs($provider)->getJson("/api/v1/me/appointments/{$uuid}")
        ->assertOk()
        ->assertJsonPath('data.counterpart.account_type', 'entrepreneur')
        ->assertJsonPath('data.counterpart.name', null)
        ->assertJsonPath('data.notes.0.body', PUBLIC_NOTE);

    $this->actingAs($provider)->postJson("/api/v1/me/appointments/{$uuid}/confirm")->assertOk()->assertJsonPath('data.counterpart.name', $client->name);

    expect($contact->fresh()?->status)->toBe(MatchRequestStatus::Accepted)
        ->and($contact->fresh()?->conversation)->not->toBeNull();
    Notification::assertNotSentTo($client, MatchingNotification::class);
});

it('referme la demande de contact au refus, au retrait et à l\'expiration', function (string $ending, MatchRequestStatus $status): void {
    [$provider, $slug, $public] = publicBookingPage();
    $client = projectOwner('particulier');
    $uuid = bookOnPage($client, $slug, $public->uuid)->json('data.uuid');

    match ($ending) {
        'refus' => $this->actingAs($provider)->postJson("/api/v1/me/appointments/{$uuid}/decline")->assertOk(),
        'retrait' => $this->actingAs($client)->postJson("/api/v1/me/appointments/{$uuid}/cancel", ['reason' => 'no_longer_needed'])->assertOk(),
        'expiration' => (function (): void {
            $this->travelTo(CarbonImmutable::parse('2027-03-22 08:00:00'));
            $this->artisan('appointments:expire')->assertSuccessful();
        })(),
    };

    expect(MatchRequest::query()->sole()->status)->toBe($status);
})->with([
    'refus' => ['refus', MatchRequestStatus::Declined],
    'retrait' => ['retrait', MatchRequestStatus::Withdrawn],
    'expiration' => ['expiration', MatchRequestStatus::Expired],
]);

it('exige un motif et un type public', function (): void {
    [, $slug, $public, $relationOnly] = publicBookingPage();
    $client = projectOwner('particulier');

    bookOnPage($client, $slug, $public->uuid, ['note' => 'Trop court'])->assertUnprocessable()->assertJsonValidationErrors('note');
    bookOnPage($client, $slug, $public->uuid, ['note' => null])->assertUnprocessable()->assertJsonValidationErrors('note');
    bookOnPage($client, $slug, $relationOnly->uuid)->assertUnprocessable()->assertJsonValidationErrors('type');

    expect(MatchRequest::query()->count())->toBe(0);
});

it('réserve la page aux particuliers et aux entrepreneurs', function (string $role): void {
    [, $slug, $public] = publicBookingPage();
    $user = $role === 'admin' ? moderator() : projectOwner($role);

    bookOnPage($user, $slug, $public->uuid)->assertForbidden();
    expect(MatchRequest::query()->count())->toBe(0);
})->with(['prestataire', 'redacteur', 'admin']);

it('renvoie vers la relation existante, et garde les règles d\'un contact', function (): void {
    [$provider, $slug, $public] = publicBookingPage();
    $client = projectOwner('particulier');

    bookOnPage($client, $slug, $public->uuid)->assertCreated();
    bookOnPage($client, $slug, $public->uuid, ['starts_at' => '2027-03-22T08:30:00Z'])->assertConflict();

    $related = projectOwner('particulier');
    [, , $relation] = acceptedConversation($related, $provider);
    bookOnPage($related, $slug, $public->uuid, ['starts_at' => '2027-03-22T09:00:00Z'])
        ->assertConflict()
        ->assertJsonPath('code', 'relation_exists')
        ->assertJsonPath('match_request', $relation->matchRequest->uuid);

    $blocked = projectOwner('particulier');
    UserBlock::query()->forceCreate(['blocker_id' => $provider->id, 'blocked_id' => $blocked->id]);
    bookOnPage($blocked, $slug, $public->uuid, ['starts_at' => '2027-03-22T09:30:00Z'])->assertForbidden();
});

it('retire un type masqué de la page et le rend signalable par le formulaire public', function (): void {
    [, $slug, $public] = publicBookingPage();

    $this->postJson('/api/v1/moderation/notices', [
        'target_type' => 'appointment_type',
        'target_ref' => $public->uuid,
        'category' => 'scam_fraud',
        'explanation' => 'Ce type de rendez-vous promet des gains garantis.',
        'name' => 'Alex Martin',
        'email' => 'alex.martin@example.org',
        'good_faith' => true,
    ])->assertCreated();

    $decision = restrictContent('appointment_type', $public->uuid);
    $this->getJson("/api/v1/providers/{$slug}/booking")->assertOk()->assertJsonCount(0, 'data.types');
    bookOnPage(projectOwner('particulier'), $slug, $public->uuid)->assertUnprocessable()->assertJsonValidationErrors('type');

    ModerationTargets::get('appointment_type')->lift($public->uuid, $decision);
    $this->getJson("/api/v1/providers/{$slug}/booking")->assertOk()->assertJsonCount(1, 'data.types');
});
