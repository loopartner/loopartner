<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/*
| Fichier .ics d'un rendez-vous (décision 56) : pour une partie connectée, avec le lieu ou le
| lien une fois confirmé ; UID stable et SEQUENCE = version (un report met à jour l'entrée).
*/

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2027-03-22 07:00:00'));
});

it('donne le fichier du rendez-vous à ses parties, avec le lien de visio une fois confirmé', function (): void {
    [$client, $provider, $matchRequest] = appointmentRelation();
    weeklyAvailability($provider);
    $type = appointmentTypeOf($provider);
    $uuid = $this->actingAs($client)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/appointments", ['type' => $type->uuid, 'starts_at' => '2027-03-22T08:00:00Z'])->json('data.uuid');

    $pending = $this->actingAs($client)->get("/api/v1/me/appointments/{$uuid}/calendar.ics")->assertOk();
    expect($pending->headers->get('Content-Type'))->toStartWith('text/calendar')
        ->and($pending->headers->get('Cache-Control'))->toContain('no-store')
        ->and($pending->getContent())->toContain('STATUS:TENTATIVE')
        ->and($pending->getContent())->not->toContain('visio.exemple.fr');

    $this->actingAs($provider)->postJson("/api/v1/me/appointments/{$uuid}/confirm")->assertOk();

    $ics = (string) $this->actingAs($client)->get("/api/v1/me/appointments/{$uuid}/calendar.ics")->assertOk()->getContent();
    expect($ics)->toContain("UID:{$uuid}@")
        ->and($ics)->toContain('SEQUENCE:1')
        ->and($ics)->toContain('DTSTART:20270322T080000Z')
        ->and($ics)->toContain('DTEND:20270322T083000Z')
        ->and($ics)->toContain('SUMMARY:Rendez-vous : Premier échange')
        ->and($ics)->toContain('URL:https://visio.exemple.fr/salle-test')
        ->and($ics)->toContain('STATUS:CONFIRMED');

    $this->actingAs(browsingProvider())->get("/api/v1/me/appointments/{$uuid}/calendar.ics")->assertNotFound();
    $this->actingAs(moderator())->get("/api/v1/me/appointments/{$uuid}/calendar.ics")->assertNotFound();
});

it('échappe le titre saisi par le prestataire', function (): void {
    [$client, $provider, $matchRequest] = appointmentRelation();
    weeklyAvailability($provider);
    $type = appointmentTypeOf($provider, ['title' => "Bilan;\nEND:VEVENT", 'auto_confirm' => true]);
    $uuid = $this->actingAs($client)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/appointments", ['type' => $type->uuid, 'starts_at' => '2027-03-22T08:00:00Z'])->json('data.uuid');

    $ics = (string) $this->actingAs($client)->get("/api/v1/me/appointments/{$uuid}/calendar.ics")->getContent();

    expect($ics)->toContain('SUMMARY:Rendez-vous : Bilan\\;\\nEND:VEVENT')
        ->and(array_count_values(explode("\r\n", $ics))['END:VEVENT'] ?? 0)->toBe(1);
});
