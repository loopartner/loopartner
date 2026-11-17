<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
| Marketplace : le journal security ne contient que des identifiants (uuid, version, motif en
| liste fermée), jamais une identité professionnelle, un montant, un titre, une ligne, un
| commentaire, une empreinte ni le nom d'un utilisateur ; les réponses n'exposent aucun
| identifiant interne.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2027-03-10 12:00:00');
    Notification::fake();
    verifiedVatRate('fr.vat.rate.standard', 2000);
});

it('n\'écrit aucune donnée sensible dans le journal pendant tout le parcours', function (): void {
    $provider = browsingProvider();
    $this->actingAs($provider)->putJson('/api/v1/me/seller-profile', sellerProfilePayload())->assertCreated();
    $offer = $this->actingAs($provider)->postJson('/api/v1/me/offers', offerPayload(['title' => 'Offre au titre confidentiel']))->assertCreated()->json('data.uuid');
    $this->actingAs($provider)->postJson("/api/v1/me/offers/{$offer}/publish")->assertOk();
    $this->actingAs($provider)->postJson("/api/v1/me/offers/{$offer}/unpublish")->assertOk();

    [$client, , $quote] = sentQuote(overrides: ['title' => 'Devis au titre confidentiel', 'notes' => 'Note confidentielle du prestataire.']);
    $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/report", ['category' => 'scam_fraud', 'comment' => 'Commentaire confidentiel du client.'])->assertCreated();
    $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/accept", ['content_hash' => $quote->content_hash, 'accept' => true, 'express_execution' => true])->assertOk();
    $this->actingAs($client)->get("/api/v1/me/quotes/{$quote->uuid}/pdf")->assertOk();
    $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/withdrawal", ['confirm' => true])->assertOk();

    [$declining, , $declined] = sentQuote();
    $this->actingAs($declining)->postJson("/api/v1/me/quotes/{$declined->uuid}/decline", ['reason' => 'price', 'comment' => 'Refus confidentiel.'])->assertOk();

    $events = array_map(fn ($record): string => $record->message, securityLog());
    $log = json_encode(array_map(fn ($record): array => $record->context, securityLog()), JSON_UNESCAPED_UNICODE);

    expect($events)->toContain(
        'marketplace.seller_profile_updated', 'marketplace.offer_published', 'marketplace.offer_unpublished',
        'marketplace.quote_sent', 'marketplace.quote_accepted', 'marketplace.quote_declined',
        'marketplace.quote_pdf_downloaded', 'marketplace.quote_withdrawal_exercised', 'moderation.report_created',
    );

    foreach ([
        '123456782', 'FR11123456782', 'Camille Exemple', 'Atelier Exemple', 'contact@atelier-exemple.test', '23 45 67 89',
        'rue de', 'Alex Martin', 'titre confidentiel', 'confidentiel', '225000', '187500', (string) $quote->content_hash,
        $client->email, $client->name, $provider->email,
    ] as $secret) {
        expect($log)->not->toContain($secret);
    }
});

it('n\'expose aucun identifiant interne dans les réponses des offres et des devis', function (): void {
    $provider = marketplaceProvider();
    $offer = publishedOfferOf($provider);
    [$client, $sender, $quote] = sentQuote();

    expectNoPrivateFields($this->getJson('/api/v1/offers')->json());
    expectNoPrivateFields($this->actingAs($provider)->getJson("/api/v1/me/offers/{$offer->uuid}")->json());

    foreach ([$client, $sender] as $party) {
        $data = $this->actingAs($party)->getJson("/api/v1/me/quotes/{$quote->uuid}")->json('data');
        unset($data['issuer']);
        expectNoPrivateFields($data);
    }
});
