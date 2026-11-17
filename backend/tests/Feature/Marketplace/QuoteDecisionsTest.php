<?php

use App\Modules\Directory\Actions\BlockUser;
use App\Modules\Marketplace\Enums\QuoteNotice;
use App\Modules\Marketplace\Enums\QuoteStatus;
use App\Modules\Marketplace\Notifications\QuoteNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
| Réponses du client à un devis (décisions 41, 42 et 46) : acceptation de ce qu'il a lu
| (empreinte), certifications, demande expresse d'exécution, refus motivé, rétractation en ligne
| d'un consommateur ; emails aux deux parties.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2027-03-10 12:00:00');
    Notification::fake();
    verifiedVatRate('fr.vat.rate.standard', 2000);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function acceptancePayload(string $hash, array $overrides = []): array
{
    return ['content_hash' => $hash, 'accept' => true, ...$overrides];
}

describe('acceptation', function (): void {
    it('accepte le contenu lu, garde la preuve et confirme aux deux parties', function (): void {
        [$client, $provider, $quote] = sentQuote();

        $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/accept", acceptancePayload((string) $quote->content_hash, ['express_execution' => true]))
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.acceptance.accepted_at', '2027-03-10T12:00:00Z')
            ->assertJsonPath('data.acceptance.express_execution', true)
            ->assertJsonPath('data.mentions.consumer.withdrawal_ends_at', '2027-03-24T12:00:00Z');

        $quote->refresh();
        expect($quote->acceptance_ip)->toBe('127.0.0.1');

        Notification::assertSentTo($provider, QuoteNotification::class, fn (QuoteNotification $n): bool => $n->notice === QuoteNotice::AcceptedForProvider && $n->notificationCategory() === 'marketplace.quote_decisions');
        Notification::assertSentTo($client, QuoteNotification::class, fn (QuoteNotification $n): bool => $n->notice === QuoteNotice::AcceptedForClient);

        $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/accept", acceptancePayload((string) $quote->content_hash))->assertConflict();
        expect(json_encode(securityLog('marketplace.quote_accepted')[0]->context))->not->toContain((string) $quote->content_hash);
    });

    it('refuse l\'acceptation par le prestataire (403) et par un tiers (404)', function (): void {
        [, $provider, $quote] = sentQuote();

        $this->actingAs($provider)->postJson("/api/v1/me/quotes/{$quote->uuid}/accept", acceptancePayload((string) $quote->content_hash))->assertForbidden();
        $this->actingAs(projectOwner('particulier'))->postJson("/api/v1/me/quotes/{$quote->uuid}/accept", acceptancePayload((string) $quote->content_hash))->assertNotFound();
    });

    it('refuse un contenu qui a changé depuis la lecture (409)', function (): void {
        [$client, , $quote] = sentQuote();

        $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/accept", acceptancePayload(str_repeat('a', 64)))->assertConflict();

        expect($quote->fresh()->status)->toBe(QuoteStatus::Sent);
    });

    it('refuse un devis dont la validité est dépassée (409)', function (): void {
        [$client, , $quote] = sentQuote();
        $this->travelTo(Carbon::parse('2027-04-10 08:00:00'));

        $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/accept", acceptancePayload((string) $quote->content_hash))->assertConflict();
    });

    it('refuse l\'acceptation entre deux utilisateurs qui se bloquent (403)', function (): void {
        [$client, $provider, $quote] = sentQuote();
        app(BlockUser::class)->handle($provider, $client->id);

        $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/accept", acceptancePayload((string) $quote->content_hash))->assertForbidden();
    });

    it('réserve la demande expresse d\'exécution à un consommateur (422)', function (): void {
        [$client, , $quote] = sentQuote('entrepreneur');

        $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/accept", acceptancePayload((string) $quote->content_hash, ['express_execution' => true]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('express_execution');
    });

    it('exige chaque certification demandée par les taux du devis, et seulement celles-là (422)', function (): void {
        verifiedVatRate('fr.vat.rate.housing-improvement', 1000, clientCertification: ['fr' => 'Je certifie que le logement est achevé depuis plus de deux ans.', 'en' => null]);
        $line = ['line_type' => 'labour', 'designation' => 'Pose de cloisons', 'quantity_milli' => 1000, 'unit' => 'lump_sum', 'unit_price_cents' => 100000, 'vat_rate_code' => 'fr.vat.rate.housing-improvement'];
        [$client, , $quote] = sentQuote(overrides: ['lines' => [$line]]);

        $this->actingAs($client)->getJson("/api/v1/me/quotes/{$quote->uuid}")
            ->assertJsonPath('data.mentions.certifications.0.code', 'fr.vat.rate.housing-improvement');

        $url = "/api/v1/me/quotes/{$quote->uuid}/accept";
        $this->actingAs($client)->postJson($url, acceptancePayload((string) $quote->content_hash))->assertUnprocessable()->assertJsonValidationErrors('certifications');
        $this->actingAs($client)->postJson($url, acceptancePayload((string) $quote->content_hash, ['certifications' => ['fr.vat.rate.housing-improvement', 'fr.vat.rate.standard']]))->assertUnprocessable();
        $this->actingAs($client)->postJson($url, acceptancePayload((string) $quote->content_hash, ['certifications' => ['fr.vat.rate.housing-improvement']]))
            ->assertOk()
            ->assertJsonPath('data.acceptance.certifications', ['fr.vat.rate.housing-improvement']);
    });

    it('exige la case d\'acceptation (422)', function (): void {
        [$client, , $quote] = sentQuote();

        $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/accept", ['content_hash' => $quote->content_hash, 'accept' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('accept');
    });

    it('limite les décisions (429)', function (): void {
        config(['security.rate_limits.quote_decisions.per_hour' => 1]);
        [$client, , $quote] = sentQuote();

        $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/accept", acceptancePayload(str_repeat('a', 64)))->assertConflict();
        $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/accept", acceptancePayload((string) $quote->content_hash))->assertTooManyRequests();
    });
});

describe('refus', function (): void {
    it('refuse avec un motif et un commentaire lus par le prestataire, qui est prévenu', function (): void {
        [$client, $provider, $quote] = sentQuote();

        $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/decline", ['reason' => 'price', 'comment' => 'Le budget dépasse ce que j\'avais prévu.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'declined');

        $this->actingAs($provider)->getJson("/api/v1/me/quotes/{$quote->uuid}")
            ->assertJsonPath('data.decline', ['reason' => 'price', 'comment' => 'Le budget dépasse ce que j\'avais prévu.']);
        Notification::assertSentTo($provider, QuoteNotification::class, fn (QuoteNotification $n): bool => $n->notice === QuoteNotice::Declined && $n->notificationCategory() === 'marketplace.quotes');

        $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/decline")->assertConflict();
        expect(json_encode(securityLog('marketplace.quote_declined')[0]->context))->not->toContain('budget');
    });

    it('refuse un motif inconnu ou un commentaire en HTML (422)', function (array $payload, string $field): void {
        [$client, , $quote] = sentQuote();

        $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/decline", $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
    })->with([
        'motif inconnu' => [['reason' => 'trop_cher'], 'reason'],
        'HTML' => [['comment' => '<a href="https://exemple.fr">ici</a>'], 'comment'],
    ]);
});

describe('rétractation', function (): void {
    it('permet à un consommateur de se rétracter pendant 14 jours, une seule fois', function (): void {
        [$client, $provider, $quote] = sentQuote();
        $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/accept", acceptancePayload((string) $quote->content_hash))->assertOk();
        $this->travel(13)->days();

        $this->actingAs($provider)->postJson("/api/v1/me/quotes/{$quote->uuid}/withdrawal", ['confirm' => true])->assertForbidden();
        $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/withdrawal")->assertUnprocessable();
        $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/withdrawal", ['confirm' => true])
            ->assertOk()
            ->assertJsonPath('data.acceptance.withdrawal_exercised_at', '2027-03-23T12:00:00Z');

        Notification::assertSentTo($provider, QuoteNotification::class, fn (QuoteNotification $n): bool => $n->notice === QuoteNotice::WithdrawalForProvider);
        Notification::assertSentTo($client, QuoteNotification::class, fn (QuoteNotification $n): bool => $n->notice === QuoteNotice::WithdrawalForClient);

        $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/withdrawal", ['confirm' => true])->assertConflict();
    });

    it('refuse une rétractation hors délai, sur un devis non accepté, ou pour un professionnel (409)', function (): void {
        [$client, , $quote] = sentQuote();
        $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/withdrawal", ['confirm' => true])->assertConflict();

        $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/accept", acceptancePayload((string) $quote->content_hash))->assertOk();
        $this->travel(15)->days();
        $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/withdrawal", ['confirm' => true])->assertConflict();

        [$business, , $businessQuote] = sentQuote('entrepreneur');
        $this->actingAs($business)->postJson("/api/v1/me/quotes/{$businessQuote->uuid}/accept", acceptancePayload((string) $businessQuote->content_hash))->assertOk();
        $this->actingAs($business)->postJson("/api/v1/me/quotes/{$businessQuote->uuid}/withdrawal", ['confirm' => true])->assertConflict();
    });
});
