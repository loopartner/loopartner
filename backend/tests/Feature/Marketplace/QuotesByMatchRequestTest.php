<?php

use App\Models\User;
use App\Modules\Directory\Actions\AcceptMatchRequest;

/*
| Devis d'une mise en relation (GET /me/quotes?match_request=<uuid>) : la messagerie affiche les
| devis de la conversation ouverte. Seuls les deux participants voient quelque chose.
*/

function sendQuoteForRequest(User $provider, string $matchRequestUuid): string
{
    $uuid = test()->actingAs($provider)->postJson('/api/v1/me/quotes', quotePayload($matchRequestUuid))->assertCreated()->json('data.uuid');
    test()->actingAs($provider)->postJson("/api/v1/me/quotes/{$uuid}/send")->assertOk();

    return $uuid;
}

it('ne renvoie que les devis de la mise en relation demandée', function (): void {
    verifiedVatRate('fr.vat.rate.standard', 2000);
    [$client, $provider, $request] = acceptedQuoteRequest();
    $wanted = sendQuoteForRequest($provider, $request->uuid);

    // Même prestataire, autre client : ne doit pas sortir.
    $otherClient = projectOwner();
    $other = app(AcceptMatchRequest::class)->handle(pendingProposal($provider, publishedProjectOf($otherClient)));
    sendQuoteForRequest($provider, $other->uuid);

    $this->actingAs($provider)->getJson("/api/v1/me/quotes?box=sent&match_request={$request->uuid}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.uuid', $wanted);

    $this->actingAs($client)->getJson("/api/v1/me/quotes?box=received&match_request={$request->uuid}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.uuid', $wanted);
});

it('ne révèle rien à un utilisateur qui ne participe pas à la mise en relation', function (): void {
    verifiedVatRate('fr.vat.rate.standard', 2000);
    [, $provider, $request] = acceptedQuoteRequest();
    sendQuoteForRequest($provider, $request->uuid);

    $outsider = marketplaceProvider();
    $this->actingAs($outsider)->getJson("/api/v1/me/quotes?box=sent&match_request={$request->uuid}")
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($outsider)->getJson('/api/v1/me/quotes?box=sent&match_request='.fake()->uuid())
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('refuse un identifiant de mise en relation mal formé', function (): void {
    $this->actingAs(marketplaceProvider())->getJson('/api/v1/me/quotes?box=sent&match_request=abc')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('match_request');
});
