<?php

namespace App\Modules\Marketplace\Actions;

use App\Models\User;
use App\Modules\Compliance\Actions\GetVatRates;
use App\Modules\Marketplace\Enums\ClientKind;
use App\Modules\Marketplace\Enums\QuoteStatus;
use App\Modules\Marketplace\Models\Offer;
use App\Modules\Marketplace\Models\Quote;
use App\Modules\Marketplace\Models\QuoteLine;
use App\Modules\Marketplace\Support\QuoteCalculator;
use App\Modules\Marketplace\Support\QuoteGuards;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Crée ou remplace un brouillon de devis (représentation complète, lignes comprises).
 *
 * - Création : conditions de QuoteGuards ; un seul brouillon et un seul devis envoyé à la fois
 *   par demande (409 : modifier le brouillon, ou faire une nouvelle version) ; au plus
 *   marketplace.quotes.max_drafts_per_provider brouillons (409).
 * - Les totaux sont recalculés à chaque enregistrement avec les taux vérifiés du jour ; ils
 *   seront recalculés à l'envoi avec les taux en vigueur ce jour-là.
 * - Un taux exclusif (franchise, autoliquidation) ne se combine avec aucun autre (422).
 *
 * @phpstan-import-type VatRate from GetVatRates
 */
class SaveQuoteDraft
{
    public function __construct(private QuoteGuards $guards) {}

    /**
     * @param  array<string, mixed>  $data  données validées, avec match_request (uuid)
     */
    public function create(User $provider, array $data): Quote
    {
        $context = $this->guards->matchRequest($provider, uuid: (string) $data['match_request']);
        $clientKind = ClientKind::fromRole($context['client_role']) ?? ClientKind::Business;
        $seller = $this->guards->seller($provider, $clientKind);
        $rates = $this->guards->vatRates($seller, now());

        return DB::transaction(function () use ($provider, $data, $context, $clientKind, $seller, $rates): Quote {
            User::query()->whereKey($provider->id)->lockForUpdate()->first();

            $open = Quote::query()
                ->where('match_request_id', $context['match_request_id'])
                ->whereIn('status', [QuoteStatus::Draft->value, QuoteStatus::Sent->value])
                ->exists();

            if ($open) {
                throw new ConflictHttpException('Un devis est déjà en cours pour cette demande : modifiez le brouillon, ou faites une nouvelle version du devis envoyé.');
            }

            if (Quote::query()->where('provider_id', $provider->id)->where('status', QuoteStatus::Draft->value)->count() >= (int) config('marketplace.quotes.max_drafts_per_provider')) {
                throw new ConflictHttpException('Vous avez atteint le nombre maximal de brouillons de devis.');
            }

            $quote = new Quote;
            $quote->forceFill([
                'uuid' => (string) Str::uuid(),
                'match_request_id' => $context['match_request_id'],
                'project_id' => $context['project']['id'] ?? null,
                'provider_id' => $provider->id,
                'client_id' => $context['client_id'],
                'client_kind' => $clientKind,
                'currency' => (string) config("marketplace.countries.{$seller->country}.currency"),
            ]);

            return $this->write($quote, $provider, $data, $rates);
        });
    }

    /**
     * @param  array<string, mixed>  $data  données validées
     */
    public function update(Quote $quote, User $provider, array $data): Quote
    {
        $seller = $this->guards->seller($provider, $quote->client_kind);
        $rates = $this->guards->vatRates($seller, now());

        return DB::transaction(function () use ($quote, $provider, $data, $rates): Quote {
            $quote = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();

            if ($quote->status !== QuoteStatus::Draft) {
                throw new ConflictHttpException('Seul un brouillon se modifie. Faites une nouvelle version de ce devis.');
            }

            return $this->write($quote, $provider, $data, $rates);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, VatRate>  $rates
     */
    private function write(Quote $quote, User $provider, array $data, array $rates): Quote
    {
        /** @var list<array<string, mixed>> $lines */
        $lines = array_values((array) $data['lines']);
        self::assertKnownRates($lines, $rates);
        self::assertExclusiveRates($lines, $rates);

        $offer = is_string($data['offer'] ?? null)
            ? Offer::query()->where('uuid', $data['offer'])->where('user_id', $provider->id)->first()
            : null;

        if (is_string($data['offer'] ?? null) && $offer === null) {
            throw ValidationException::withMessages(['offer' => 'Cette offre n\'existe pas parmi les vôtres.']);
        }

        $client = (array) $data['client'];
        $isFree = (bool) ($data['quote_is_free'] ?? true);

        $quote->fill([
            'title' => $data['title'],
            'scope' => $data['scope'],
            'validity_days' => (int) ($data['validity_days'] ?? config('marketplace.quotes.validity_days.default')),
            'start_date' => $data['start_date'] ?? null,
            'duration' => $data['duration'] ?? null,
            'payment_terms' => $data['payment_terms'] ?? null,
            'deposit_cents' => $data['deposit_cents'] ?? null,
            'deposit_kind' => isset($data['deposit_cents']) ? ($data['deposit_kind'] ?? null) : null,
            'quote_is_free' => $isFree,
            'quote_fee_cents' => $isFree ? null : ($data['quote_fee_cents'] ?? null),
            'keep_replaced_parts' => $data['keep_replaced_parts'] ?? null,
            'notes' => $data['notes'] ?? null,
        ])->forceFill([
            'offer_uuid' => $offer?->uuid,
            'offer_title' => $offer?->title,
            'client_snapshot' => [
                'name' => $client['name'],
                'company_name' => $client['company_name'] ?? null,
                'registration_number' => $client['registration_number'] ?? null,
                'address' => $client['address'] ?? null,
                'execution_place' => $client['execution_place'],
            ],
        ]);

        $rows = array_map(fn (array $line): array => [
            'quantity_milli' => (int) $line['quantity_milli'],
            'unit_price_cents' => (int) $line['unit_price_cents'],
            'vat_rate_code' => (string) $line['vat_rate_code'],
            'vat_basis_points' => $rates[(string) $line['vat_rate_code']]['basis_points'],
        ], $lines);
        $totals = QuoteCalculator::compute($rows);

        $quote->forceFill([
            'total_ht_cents' => $totals['total_ht_cents'],
            'total_vat_cents' => $totals['total_vat_cents'],
            'total_ttc_cents' => $totals['total_ttc_cents'],
            'vat_breakdown' => $totals['breakdown'],
        ])->save();

        $quote->lines()->delete();

        foreach ($lines as $position => $line) {
            $row = new QuoteLine;
            $row->forceFill([
                'quote_id' => $quote->id,
                'position' => $position + 1,
                'line_type' => $line['line_type'],
                'designation' => $line['designation'],
                'details' => $line['details'] ?? null,
                'quantity_milli' => $rows[$position]['quantity_milli'],
                'unit' => $line['unit'],
                'unit_price_cents' => $rows[$position]['unit_price_cents'],
                'vat_rate_code' => $rows[$position]['vat_rate_code'],
                'vat_basis_points' => $rows[$position]['vat_basis_points'],
                'total_ht_cents' => $totals['lines'][$position]['total_ht_cents'],
            ])->save();
        }

        return $quote->load('lines');
    }

    /**
     * Chaque ligne cite le code d'un taux vérifié en vigueur (422 sur la ligne sinon).
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, VatRate>  $rates
     */
    public static function assertKnownRates(array $lines, array $rates): void
    {
        $errors = [];

        foreach ($lines as $index => $line) {
            if (! array_key_exists((string) $line['vat_rate_code'], $rates)) {
                $errors["lines.{$index}.vat_rate_code"] = 'Ce taux de TVA n\'est pas un taux vérifié en vigueur.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Un taux exclusif (franchise en base, autoliquidation) s'applique seul à tout le devis.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, VatRate>  $rates
     */
    public static function assertExclusiveRates(array $lines, array $rates): void
    {
        $codes = array_values(array_unique(array_map(fn (array $line): string => (string) $line['vat_rate_code'], $lines)));

        if (count($codes) < 2) {
            return;
        }

        foreach ($codes as $code) {
            if ($rates[$code]['exclusive'] ?? false) {
                throw ValidationException::withMessages(['lines' => 'Le taux « '.($rates[$code]['label']['fr'] ?? $code).' » s\'applique seul à tout le devis : il ne se combine avec aucun autre taux.']);
            }
        }
    }
}
