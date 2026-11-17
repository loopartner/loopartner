<?php

use App\Models\User;
use App\Modules\Accounts\Actions\DeleteAccount;
use App\Modules\Directory\Actions\AcceptMatchRequest;
use App\Modules\Directory\Actions\PruneMatchingData;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Marketplace\Enums\QuoteStatus;
use App\Modules\Marketplace\Models\Offer;
use App\Modules\Marketplace\Models\Quote;
use App\Modules\Marketplace\Models\SellerProfile;
use App\Modules\Moderation\Models\Notice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
| Devis et offres dans la durée (décisions 45 et 47) : signalement d'un devis par son client,
| masquage, expiration horaire, purge, suppression de compte.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2027-03-10 12:00:00');
    Notification::fake();
    verifiedVatRate('fr.vat.rate.standard', 2000);
});

describe('signalement d\'un devis', function (): void {
    it('permet au client de signaler un devis reçu, une fois tant qu\'il est examiné', function (): void {
        [$client, $provider, $quote] = sentQuote();

        $this->actingAs($provider)->postJson("/api/v1/me/quotes/{$quote->uuid}/report", ['category' => 'scam_fraud'])->assertForbidden();
        $this->actingAs(projectOwner('particulier'))->postJson("/api/v1/me/quotes/{$quote->uuid}/report", ['category' => 'scam_fraud'])->assertNotFound();

        $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/report", ['category' => 'scam_fraud', 'comment' => 'Le prestataire demande un virement sur un compte étranger.'])
            ->assertCreated()
            ->assertJsonPath('data.target_type', 'quote')
            ->assertJsonPath('data.status', 'open');
        $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/report", ['category' => 'scam_fraud'])->assertConflict();

        expect(Notice::query()->count())->toBe(1)
            ->and(json_encode(securityLog('moderation.report_created')[0]->context))->not->toContain('virement');
    });

    it('refuse une catégorie inconnue (422) et le formulaire public (non signalable sans compte)', function (): void {
        [$client, , $quote] = sentQuote();

        $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/report", ['category' => 'spam'])->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->postJson('/api/v1/moderation/notices', [
            'target_type' => 'quote',
            'target_ref' => $quote->uuid,
            'category' => 'scam_fraud',
            'explanation' => 'Ce devis promet des gains garantis et renvoie vers un site frauduleux.',
            'name' => 'Alex Martin',
            'email' => 'alex@example.org',
            'good_faith' => true,
        ])->assertUnprocessable();
    });

    it('cache au client le contenu d\'un devis masqué, qui ne peut plus être accepté ni téléchargé', function (): void {
        [$client, $provider, $quote] = sentQuote();
        restrictContent('quote', $quote->uuid);

        $this->actingAs($client)->getJson("/api/v1/me/quotes/{$quote->uuid}")
            ->assertOk()
            ->assertJsonPath('data.lines', null)
            ->assertJsonPath('data.totals', null)
            ->assertJsonPath('data.issuer', null);
        $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/accept", ['content_hash' => $quote->content_hash, 'accept' => true])->assertConflict();
        $this->actingAs($client)->getJson("/api/v1/me/quotes/{$quote->uuid}/pdf")->assertNotFound();
        $this->actingAs($provider)->getJson("/api/v1/me/quotes/{$quote->uuid}")
            ->assertJsonPath('data.moderation.restricted', true)
            ->assertJsonCount(2, 'data.lines');
    });
});

describe('expiration et purge', function (): void {
    it('fait expirer les devis envoyés à la fin de leur validité, chaque heure', function (): void {
        [, , $quote] = sentQuote();
        $this->travelTo(Carbon::parse('2027-04-09 21:59:00'));
        $this->artisan('marketplace:expire-quotes')->assertSuccessful();
        expect($quote->fresh()->status)->toBe(QuoteStatus::Sent);

        $this->travelTo(Carbon::parse('2027-04-09 22:00:00'));
        $this->artisan('marketplace:expire-quotes')->expectsOutputToContain('1 devis expiré(s).')->assertSuccessful();

        expect($quote->fresh()->status)->toBe(QuoteStatus::Expired);
    });

    it('purge les brouillons et les devis clos après 12 mois, jamais les devis acceptés', function (): void {
        [$client, , $accepted] = sentQuote();
        $this->actingAs($client)->postJson("/api/v1/me/quotes/{$accepted->uuid}/accept", ['content_hash' => $accepted->content_hash, 'accept' => true])->assertOk();
        [$declinedClient, , $declined] = sentQuote();
        $this->actingAs($declinedClient)->postJson("/api/v1/me/quotes/{$declined->uuid}/decline")->assertOk();

        $this->travelTo(Carbon::parse('2028-03-11 12:00:00'));
        $this->artisan('marketplace:prune')->expectsOutputToContain('0 brouillon(s), 1 devis clos supprimé(s).')->assertSuccessful();

        expect(Quote::query()->whereKey($declined->id)->exists())->toBeFalse()
            ->and(Quote::query()->whereKey($accepted->id)->exists())->toBeTrue();
    });

    it('supprime un brouillon resté sans modification 12 mois', function (): void {
        [, $provider, $matchRequest] = acceptedQuoteRequest();
        $uuid = $this->actingAs($provider)->postJson('/api/v1/me/quotes', quotePayload($matchRequest->uuid))->assertCreated()->json('data.uuid');

        $this->travelTo(Carbon::parse('2028-03-11 12:00:00'));
        $this->artisan('marketplace:prune')->assertSuccessful();

        expect(Quote::query()->where('uuid', $uuid)->exists())->toBeFalse();
    });
});

describe('suppression de compte', function (): void {
    it('supprime offres, identité et devis non acceptés du prestataire, et garde le devis accepté pour le client', function (): void {
        [$client, $provider, $accepted] = sentQuote();
        $this->actingAs($client)->postJson("/api/v1/me/quotes/{$accepted->uuid}/accept", ['content_hash' => $accepted->content_hash, 'accept' => true])->assertOk();
        publishedOfferOf($provider);
        $open = $this->actingAs($provider)->postJson('/api/v1/me/quotes', quotePayload(acceptedQuoteRequestFor($provider)->uuid))->assertCreated()->json('data.uuid');

        app(DeleteAccount::class)->handle($provider->fresh());

        expect(Offer::query()->count())->toBe(0)
            ->and(SellerProfile::query()->count())->toBe(0)
            ->and(Quote::query()->where('uuid', $open)->exists())->toBeFalse()
            ->and($accepted->fresh()?->provider_id)->toBeNull();

        $this->actingAs($client)->getJson("/api/v1/me/quotes/{$accepted->uuid}")
            ->assertOk()
            ->assertJsonPath('data.provider', ['deleted' => true])
            ->assertJsonPath('data.issuer.legal_name', 'Camille Exemple');
    });

    it('supprime le devis accepté avec la conversation orpheline, après sa durée de conservation', function (): void {
        [$client, $provider, $accepted] = sentQuote();
        $this->actingAs($client)->postJson("/api/v1/me/quotes/{$accepted->uuid}/accept", ['content_hash' => $accepted->content_hash, 'accept' => true])->assertOk();
        app(DeleteAccount::class)->handle($provider->fresh());

        $this->travelTo(Carbon::parse('2028-03-11 12:00:00'));
        app(PruneMatchingData::class)->handle();

        expect(Quote::query()->whereKey($accepted->id)->exists())->toBeFalse();
    });

    it('supprime les devis reçus non acceptés d\'un client qui supprime son compte', function (): void {
        [$client, , $quote] = sentQuote();

        app(DeleteAccount::class)->handle($client->fresh());

        expect(Quote::query()->whereKey($quote->id)->exists())->toBeFalse();
    });
});

/**
 * Nouvelle demande acceptée entre ce prestataire et un nouveau porteur de projet.
 */
function acceptedQuoteRequestFor(User $provider): MatchRequest
{
    return app(AcceptMatchRequest::class)->handle(pendingProposal($provider, publishedProjectOf(projectOwner('particulier'))));
}
