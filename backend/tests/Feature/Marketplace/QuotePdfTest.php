<?php

use App\Models\Media;
use App\Modules\Accounts\Models\ProviderProfile;
use App\Modules\Marketplace\Enums\QuoteNotice;
use App\Modules\Marketplace\Models\Quote;
use App\Modules\Marketplace\Models\SellerProfile;
use App\Modules\Marketplace\Notifications\QuoteNotification;
use App\Modules\Marketplace\Support\QuotePdf;
use App\Support\Media\MediaPurpose;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| PDF des devis (décisions 43, 46 et 48) : gratuit pour les deux parties, construit à partir du
| contenu figé, sans donnée d'un tiers ; logo et mentions personnalisées en Premium ; joint à la
| confirmation d'acceptation.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2027-03-10 12:00:00');
    Notification::fake();
    verifiedVatRate('fr.vat.rate.standard', 2000, mention: null);
});

describe('téléchargement', function (): void {
    it('donne le PDF du devis à ses deux parties, sans cache', function (): void {
        [$client, $provider, $quote] = sentQuote();

        foreach ([$client, $provider] as $party) {
            $response = $this->actingAs($party)->get("/api/v1/me/quotes/{$quote->uuid}/pdf")
                ->assertOk()
                ->assertHeader('Content-Type', 'application/pdf')
                ->assertHeader('Content-Disposition', 'attachment; filename="devis-D-2027-0001-v1.pdf"');

            expect(substr((string) $response->getContent(), 0, 5))->toBe('%PDF-')
                ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
        }

        expect(securityLog('marketplace.quote_pdf_downloaded'))->toHaveCount(2);
    });

    it('cache le PDF à tout autre utilisateur (404) et n\'en fait pas pour un brouillon (409)', function (): void {
        [, $provider, $quote] = sentQuote();

        $this->actingAs(moderator())->getJson("/api/v1/me/quotes/{$quote->uuid}/pdf")->assertNotFound();
        $this->actingAs(projectOwner('particulier'))->getJson("/api/v1/me/quotes/{$quote->uuid}/pdf")->assertNotFound();

        $draft = $this->actingAs($provider)->postJson("/api/v1/me/quotes/{$quote->uuid}/revise")->assertCreated()->json('data.uuid');
        $this->actingAs($provider)->getJson("/api/v1/me/quotes/{$draft}/pdf")->assertConflict();
    });

    it('limite les PDF (429)', function (): void {
        config(['security.rate_limits.quote_pdfs.per_hour' => 1]);
        [$client, , $quote] = sentQuote();

        $this->actingAs($client)->get("/api/v1/me/quotes/{$quote->uuid}/pdf")->assertOk();
        $this->actingAs($client)->get("/api/v1/me/quotes/{$quote->uuid}/pdf")->assertTooManyRequests();
    });
});

describe('contenu', function (): void {
    it('contient les mentions obligatoires du devis, sans coordonnée d\'un utilisateur', function (): void {
        [$client, , $quote] = sentQuote();
        $html = app(QuotePdf::class)->html($quote->load('lines'), 'fr');

        expect($html)
            ->toContain('Camille Exemple EI', 'Atelier Exemple', '123456782', 'RNE', '1 rue de l&#039;Exemple', '75001 Paris')
            ->toContain('Devis D-2027-0001', 'Établi le 10/03/2027', "Valable jusqu'au 09/04/2027")
            ->toContain('Alex Martin', "Lieu d'exécution : À distance")
            ->toContain('Conception et intégration', '3,5', 'jour', '450,00', '20', '%')
            ->toContain("2\u{202F}250,00", 'Devis gratuit', 'Paiement à réception de facture')
            ->toContain('Assurance Exemple', 'Médiateur Exemple', 'https://www.mediateur-exemple.fr')
            ->toContain('Droit de rétractation : 14 jours', (string) $quote->content_hash, "n'est pas partie au contrat")
            ->not->toContain($client->email)
            ->not->toContain('<img');
    });

    it('n\'annonce pas de droit de rétractation à un professionnel et indique l\'acceptation', function (): void {
        [$client, , $quote] = sentQuote('entrepreneur');
        $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/accept", ['content_hash' => $quote->content_hash, 'accept' => true])->assertOk();

        $html = app(QuotePdf::class)->html($quote->fresh()->load('lines'), 'fr');

        expect($html)->not->toContain('Droit de rétractation')
            ->toContain('Devis accepté en ligne par le client le 10/03/2027');
    });

    it('échappe le texte saisi', function (): void {
        [, , $quote] = sentQuote(overrides: ['client' => ['name' => 'Alex & Co "Atelier"', 'execution_place' => 'Lyon']]);

        expect(app(QuotePdf::class)->html($quote->load('lines'), 'fr'))->toContain('Alex &amp; Co &quot;Atelier&quot;');
    });

    it('ajoute le logo et les mentions personnalisées d\'un prestataire Premium, figés à l\'envoi', function (): void {
        Storage::fake('public');
        $client = projectOwner('particulier');
        [, , $free] = sentQuote(client: $client);
        expect(app(QuotePdf::class)->html($free->load('lines'), 'fr'))->not->toContain('Mentions de test');

        [, $provider, $matchRequest] = acceptedQuoteRequest();
        makePremium($provider);
        SellerProfile::query()->where('user_id', $provider->id)->update(['quote_footer' => encrypt('Mentions de test du prestataire', false)]);

        $logo = Media::factory()->create(['user_id' => $provider->id, 'purpose' => MediaPurpose::ProviderLogo, 'attached_at' => now()]);
        Storage::disk('public')->put($logo->path, (string) file_get_contents(testImage('png', 200, 200)->getRealPath()));
        ProviderProfile::query()->where('user_id', $provider->id)->update(['logo_media_id' => $logo->id]);

        $uuid = $this->actingAs($provider)->postJson('/api/v1/me/quotes', quotePayload($matchRequest->uuid))->assertCreated()->json('data.uuid');
        $this->actingAs($provider)->postJson("/api/v1/me/quotes/{$uuid}/send")->assertOk();
        $quote = Quote::query()->where('uuid', $uuid)->sole();

        expect(app(QuotePdf::class)->html($quote->load('lines'), 'fr'))
            ->toContain('Mentions de test du prestataire')
            ->toContain('<img src="data:image/png;base64,');
    });
});

describe('confirmation d\'acceptation', function (): void {
    it('joint le PDF du devis accepté à l\'email des deux parties, sans montant dans le corps', function (): void {
        [$client, $provider, $quote] = sentQuote();
        $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/accept", ['content_hash' => $quote->content_hash, 'accept' => true])->assertOk();

        foreach ([[$client, QuoteNotice::AcceptedForClient], [$provider, QuoteNotice::AcceptedForProvider]] as [$recipient, $notice]) {
            Notification::assertSentTo($recipient, QuoteNotification::class, function (QuoteNotification $notification) use ($recipient, $notice): bool {
                if ($notification->notice !== $notice) {
                    return false;
                }

                $mail = $notification->toMail($recipient);
                $body = (string) $mail->render();

                return count($mail->rawAttachments) === 1
                    && $mail->rawAttachments[0]['name'] === 'devis-D-2027-0001-v1.pdf'
                    && str_starts_with($mail->rawAttachments[0]['data'], '%PDF-')
                    && ! str_contains($body, '2 250')
                    && ! str_contains($body, 'Conception');
            });
        }
    });
});
