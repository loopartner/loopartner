<?php

use App\Modules\Directory\Actions\ManageAppointmentContact;
use App\Modules\Directory\Enums\MatchRequestStatus;
use App\Modules\Directory\Enums\MatchRequestType;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Models\UserBlock;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/*
| Action publique ManageAppointmentContact : demande de contact d'une réservation sur la page
| publique d'un prestataire (module Appointments), sans email du module Directory.
*/

const APPOINTMENT_CONTACT_MESSAGE = 'Bonjour, je souhaite un premier échange au sujet de mon projet.';

it('crée une demande de contact en attente, sans email', function (): void {
    Notification::fake();
    $client = projectOwner('particulier');
    $provider = browsingProvider();

    $result = app(ManageAppointmentContact::class)->submit($client, $provider->id, APPOINTMENT_CONTACT_MESSAGE);

    $request = MatchRequest::query()->findOrFail($result['match_request_id']);
    expect($request->uuid)->toBe($result['uuid'])
        ->and($request->type)->toBe(MatchRequestType::Contact)
        ->and($request->status)->toBe(MatchRequestStatus::Pending)
        ->and($request->recipient_id)->toBe($provider->id)
        ->and($request->message)->toBe(APPOINTMENT_CONTACT_MESSAGE);
    Notification::assertNothingSent();
});

it('garde les règles d\'un contact : blocage et demande déjà active', function (): void {
    Notification::fake();
    $client = projectOwner('particulier');
    $provider = browsingProvider();
    $contact = app(ManageAppointmentContact::class);

    $contact->submit($client, $provider->id, APPOINTMENT_CONTACT_MESSAGE);
    expect(fn () => $contact->submit($client, $provider->id, APPOINTMENT_CONTACT_MESSAGE))->toThrow(ConflictHttpException::class);

    $other = projectOwner('entrepreneur');
    UserBlock::query()->forceCreate(['blocker_id' => $provider->id, 'blocked_id' => $other->id]);
    expect(fn () => $contact->submit($other, $provider->id, APPOINTMENT_CONTACT_MESSAGE))->toThrow(AccessDeniedHttpException::class);
});

it('accepte la demande et ouvre la conversation, sans email', function (): void {
    Notification::fake();
    $client = projectOwner('particulier');
    $provider = browsingProvider();
    $contact = app(ManageAppointmentContact::class);
    $result = $contact->submit($client, $provider->id, APPOINTMENT_CONTACT_MESSAGE);

    $contact->accept($result['match_request_id']);

    $request = MatchRequest::query()->with('conversation')->findOrFail($result['match_request_id']);
    expect($request->status)->toBe(MatchRequestStatus::Accepted)
        ->and($request->conversation)->not->toBeNull()
        ->and($contact->acceptedBetween($client->id, $provider->id))->toBe($result['uuid']);
    Notification::assertNothingSent();

    expect(fn () => $contact->accept($result['match_request_id']))->toThrow(ConflictHttpException::class);
});

it('refuse, retire ou fait expirer une demande en attente, et ignore une demande déjà traitée', function (string $method, MatchRequestStatus $status): void {
    Notification::fake();
    $client = projectOwner('particulier');
    $provider = browsingProvider();
    $contact = app(ManageAppointmentContact::class);
    $result = $contact->submit($client, $provider->id, APPOINTMENT_CONTACT_MESSAGE);

    $contact->{$method}($result['match_request_id']);
    $contact->{$method}($result['match_request_id']);

    $request = MatchRequest::query()->findOrFail($result['match_request_id']);
    expect($request->status)->toBe($status)
        ->and($request->active_key)->toBeNull()
        ->and($contact->acceptedBetween($client->id, $provider->id))->toBeNull();
    Notification::assertNothingSent();
})->with([
    'refus' => ['decline', MatchRequestStatus::Declined],
    'retrait' => ['withdraw', MatchRequestStatus::Withdrawn],
    'expiration' => ['expire', MatchRequestStatus::Expired],
]);

it('trouve une relation acceptée née d\'une proposition, et rien pour un tiers', function (): void {
    [$owner, $provider] = acceptedConversation();

    expect(app(ManageAppointmentContact::class)->acceptedBetween($owner->id, $provider->id))->toBeString()
        ->and(app(ManageAppointmentContact::class)->acceptedBetween(projectOwner('particulier')->id, $provider->id))->toBeNull();
});
