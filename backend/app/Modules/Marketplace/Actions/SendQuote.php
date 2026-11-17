<?php

namespace App\Modules\Marketplace\Actions;

use App\Models\User;
use App\Modules\Accounts\Actions\GetEntitlements;
use App\Modules\Marketplace\Enums\QuoteNotice;
use App\Modules\Marketplace\Enums\QuoteStatus;
use App\Modules\Marketplace\Models\Quote;
use App\Modules\Marketplace\Models\QuoteLine;
use App\Modules\Marketplace\Models\SellerProfile;
use App\Modules\Marketplace\Notifications\QuoteNotification;
use App\Modules\Marketplace\Support\QuoteCalculator;
use App\Modules\Marketplace\Support\QuoteDocument;
use App\Modules\Marketplace\Support\QuoteGuards;
use App\Modules\Marketplace\Support\QuoteLogo;
use App\Support\SecurityLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Envoie un brouillon au client et fige son contenu.
 *
 * - Conditions de QuoteGuards revérifiées (demande acceptée, pas de blocage, identité
 *   complète, médiateur pour un consommateur, taux vérifiés).
 * - Chaque ligne reçoit le taux vérifié en vigueur le jour de l'envoi (code, version, taux,
 *   libellé, mention, certification) ; un taux qui n'est plus en vigueur donne 422.
 * - Numéro D-AAAA-NNNN attribué au premier envoi, sous verrou de l'identité professionnelle ;
 *   une nouvelle version garde le numéro de la précédente.
 * - Validité : jusqu'à la fin du jour (fuseau du pays du prestataire) validity_days après
 *   l'envoi. Le devis envoyé précédent de la même demande est remplacé (superseded).
 * - content_hash : empreinte du contenu, contrôlée à l'acceptation.
 * - Le client reçoit un email sans montant ni ligne (catégorie marketplace.quotes).
 */
class SendQuote
{
    public function __construct(private QuoteGuards $guards, private GetEntitlements $entitlements, private QuoteLogo $logo) {}

    public function handle(User $provider, Quote $quote): Quote
    {
        if ($quote->status !== QuoteStatus::Draft) {
            throw new ConflictHttpException('Ce devis a déjà été envoyé.');
        }

        $this->guards->matchRequest($provider, id: $quote->match_request_id);
        $seller = $this->guards->seller($provider, $quote->client_kind);
        $rates = $this->guards->vatRates($seller, now());
        $branded = $this->entitlements->handle($provider)->allows('marketplace.quote_branding');
        // Copie figée du logo (décision 51), préparée hors de la transaction (conversion d'image).
        $logo = $branded ? $this->logo->capture($provider->id) : null;

        $quote = DB::transaction(function () use ($quote, $seller, $rates, $branded, $logo): Quote {
            $seller = SellerProfile::query()->whereKey($seller->id)->lockForUpdate()->firstOrFail();
            $quote = Quote::query()->with('lines')->whereKey($quote->id)->lockForUpdate()->firstOrFail();

            if ($quote->status !== QuoteStatus::Draft) {
                throw new ConflictHttpException('Ce devis a déjà été envoyé.');
            }

            $lines = $quote->lines->map(fn (QuoteLine $line): array => ['vat_rate_code' => $line->vat_rate_code])->all();
            SaveQuoteDraft::assertKnownRates($lines, $rates);
            SaveQuoteDraft::assertExclusiveRates($lines, $rates);

            $totals = QuoteCalculator::compute($quote->lines->map(fn (QuoteLine $line): array => [
                'quantity_milli' => $line->quantity_milli,
                'unit_price_cents' => $line->unit_price_cents,
                'vat_rate_code' => $line->vat_rate_code,
                'vat_basis_points' => $rates[$line->vat_rate_code]['basis_points'],
            ])->values()->all());

            foreach ($quote->lines->values() as $index => $line) {
                $rate = $rates[$line->vat_rate_code];
                $line->forceFill([
                    'vat_rate_version' => $rate['version'],
                    'vat_basis_points' => $rate['basis_points'],
                    'vat_rate' => [
                        'label' => $rate['label'],
                        'mention' => $rate['mention'],
                        'client_certification' => $rate['client_certification'],
                        'exclusive' => $rate['exclusive'],
                    ],
                    'total_ht_cents' => $totals['lines'][$index]['total_ht_cents'],
                ])->save();
            }

            $timezone = (string) config("marketplace.countries.{$seller->country}.timezone", 'UTC');
            $validUntil = CarbonImmutable::now($timezone)->startOfDay()->addDays($quote->validity_days);

            $quote->forceFill([
                'number' => $quote->number ?? self::nextNumber($seller),
                // Personnalisation Premium figée à l'envoi : copie du logo et mentions du prestataire.
                // L'empreinte du logo entre dans content_hash : le client accepte aussi ce logo.
                'issuer_snapshot' => [
                    ...QuoteDocument::issuer($seller),
                    'branding' => $branded ? [
                        'logo' => $logo !== null,
                        'logo_sha256' => $logo === null ? null : QuoteLogo::fingerprint($logo),
                        'footer' => $seller->quote_footer,
                    ] : null,
                ],
                'issuer_logo' => $logo,
                'total_ht_cents' => $totals['total_ht_cents'],
                'total_vat_cents' => $totals['total_vat_cents'],
                'total_ttc_cents' => $totals['total_ttc_cents'],
                'vat_breakdown' => $totals['breakdown'],
                'valid_until' => $validUntil->toDateString(),
                'expires_at' => $validUntil->endOfDay()->utc(),
                'status' => QuoteStatus::Sent,
                'sent_at' => now(),
            ]);
            $quote->setRelation('lines', $quote->lines()->get());
            $quote->content_hash = QuoteDocument::hash($quote);
            $quote->save();

            Quote::query()
                ->where('match_request_id', $quote->match_request_id)
                ->whereKeyNot($quote->id)
                ->where('status', QuoteStatus::Sent->value)
                ->update(['status' => QuoteStatus::Superseded->value, 'superseded_at' => now()]);

            return $quote;
        });

        QuoteNotification::sendTo($quote->client, QuoteNotice::Received, $quote);
        SecurityLog::record('marketplace.quote_sent', ['quote' => $quote->uuid, 'version' => $quote->version]);

        return $quote;
    }

    /**
     * Numéro suivant du prestataire pour l'année en cours (D-AAAA-NNNN), sous verrou de son
     * identité professionnelle.
     */
    private static function nextNumber(SellerProfile $seller): string
    {
        $year = (int) now()->format('Y');
        $sequence = $seller->last_quote_year === $year ? $seller->last_quote_sequence + 1 : 1;

        if ($sequence > 99_999) {
            throw ValidationException::withMessages(['quote' => 'Le nombre maximal de devis pour cette année est atteint.']);
        }

        $seller->forceFill(['last_quote_year' => $year, 'last_quote_sequence' => $sequence])->save();

        return sprintf('D-%d-%04d', $year, $sequence);
    }
}
