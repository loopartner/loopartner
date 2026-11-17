<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Accounts\Actions\GetProviderSummaries;
use App\Modules\Marketplace\Enums\QuoteLineType;
use App\Modules\Marketplace\Enums\QuoteUnit;
use App\Modules\Marketplace\Models\Quote;
use App\Modules\Marketplace\Models\QuoteLine;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Comparateur de devis à règles explicables, sans IA (décisions 33 et 50).
 *
 * Il ne classe pas les prestataires et ne donne aucun avis sur la qualité : il met les devis
 * reçus pour un même projet côte à côte (ordre de réception), relève les éléments manquants et
 * propose des questions à poser. Chaque constat donne sa règle (rule), sa gravité (info ou
 * attention), le devis concerné (null : constat sur l'ensemble), un message, sa raison
 * (reason : le calcul ou la donnée qui le justifie) et, le cas échéant, une question.
 *
 * Règles et seuils : config/marketplace.php (comparison.rules), des repères de lecture et non
 * des valeurs réglementaires. Tous les calculs sont faits en entiers.
 *
 * Lot 5 : la brique d'IA pourra ajouter ses propres constats (facultatifs) à côté de ceux-ci ;
 * le comparateur reste complet sans elle.
 *
 * @phpstan-type Finding array{rule: string, severity: string, quote: string|null, message: string, reason: string, question: string|null}
 */
final class QuoteComparison
{
    /**
     * @var array<string, array<string, array{message: string, reason: string, question?: string}>>
     */
    private const TEXTS = [
        'fr' => [
            'missing_start_date' => ['message' => 'Aucune date de début n\'est indiquée.', 'reason' => 'Le champ « date de début » du devis :number est vide.', 'question' => 'À partir de quelle date pouvez-vous commencer ?'],
            'missing_duration' => ['message' => 'Aucune durée de réalisation n\'est indiquée.', 'reason' => 'Le champ « durée » du devis :number est vide.', 'question' => 'Combien de temps durera la prestation ?'],
            'missing_payment_terms' => ['message' => 'Les conditions de paiement ne sont pas précisées.', 'reason' => 'Le champ « conditions de paiement » du devis :number est vide.', 'question' => 'Quand et comment le paiement est-il attendu ?'],
            'deposit_requested' => ['message' => 'Une somme est demandée à la commande (:kind) : :amount.', 'reason' => 'Le devis :number indique :amount à verser à la commande, au titre :kind_article. Un acompte engage les deux parties ; des arrhes permettent à chacune de se dédire.', 'question' => 'Dans quels cas cette somme est-elle remboursée ?'],
            'missing_travel_costs' => ['message' => 'Aucun frais de déplacement n\'apparaît.', 'reason' => 'Le projet se réalise sur place et le devis :number ne contient aucune ligne « Déplacement ».', 'question' => 'Les frais de déplacement sont-ils inclus dans le prix ?'],
            'single_lump_sum' => ['message' => 'Un forfait représente l\'essentiel du devis : le détail est limité.', 'reason' => 'La ligne forfaitaire « :designation » représente :share % du total HT du devis :number (repère : :threshold %).', 'question' => 'Pouvez-vous détailler ce que comprend ce forfait ?'],
            'missing_insurance' => ['message' => 'Aucune assurance professionnelle n\'est indiquée.', 'reason' => 'L\'identité professionnelle copiée dans le devis :number ne mentionne pas d\'assureur.', 'question' => 'Êtes-vous assuré pour cette prestation ? Pouvez-vous m\'envoyer votre attestation ?'],
            'validity_ending' => ['message' => 'Ce devis expire bientôt.', 'reason' => 'Le devis :number est valable jusqu\'au :date, dans :days jour(s).', 'question' => 'Pouvez-vous prolonger la validité de ce devis ?'],
            'validity_expired' => ['message' => 'Ce devis n\'est plus valable.', 'reason' => 'La validité du devis :number a pris fin le :date.', 'question' => 'Pouvez-vous m\'envoyer une nouvelle version de ce devis ?'],
            'price_gap_above' => ['message' => 'Ce devis est nettement plus cher que la médiane des devis reçus.', 'reason' => 'Total TTC du devis :number : :amount, soit :gap % au-dessus de la médiane (:median) des :count devis comparés (repère : :threshold %).', 'question' => 'Qu\'est-ce qui explique cet écart de prix ?'],
            'price_gap_below' => ['message' => 'Ce devis est nettement moins cher que la médiane des devis reçus.', 'reason' => 'Total TTC du devis :number : :amount, soit :gap % au-dessous de la médiane (:median) des :count devis comparés (repère : :threshold %).', 'question' => 'Tous les postes des autres devis sont-ils bien compris dans votre prix ?'],
            'missing_line_type' => ['message' => 'Un poste présent dans la plupart des autres devis n\'apparaît pas.', 'reason' => ':present devis sur :others autres comportent une ligne « :type », pas le devis :number.', 'question' => 'Le poste « :type » est-il compris dans votre devis ?'],
            'mixed_units' => ['message' => 'Un même poste est compté dans des unités différentes : comparez les totaux plutôt que les prix unitaires.', 'reason' => 'Le poste « :type » est compté en :units selon les devis.'],
            'earliest_start' => ['message' => 'Ce devis propose la date de début la plus proche.', 'reason' => 'Le devis :number propose de commencer le :date, la date la plus proche parmi les devis qui en indiquent une.'],
        ],
        'en' => [
            'missing_start_date' => ['message' => 'No start date is given.', 'reason' => 'The "start date" field of quote :number is empty.', 'question' => 'When can you start?'],
            'missing_duration' => ['message' => 'No duration is given.', 'reason' => 'The "duration" field of quote :number is empty.', 'question' => 'How long will the work take?'],
            'missing_payment_terms' => ['message' => 'Payment terms are not specified.', 'reason' => 'The "payment terms" field of quote :number is empty.', 'question' => 'When and how is payment expected?'],
            'deposit_requested' => ['message' => 'A payment is requested on order (:kind): :amount.', 'reason' => 'Quote :number asks for :amount on order, as :kind_article. A deposit binds both parties; earnest money lets either party withdraw.', 'question' => 'In which cases is this amount refunded?'],
            'missing_travel_costs' => ['message' => 'No travel costs appear.', 'reason' => 'The project takes place on site and quote :number has no "Travel" line.', 'question' => 'Are travel costs included in the price?'],
            'single_lump_sum' => ['message' => 'A lump sum makes up most of the quote: details are limited.', 'reason' => 'The lump-sum line ":designation" is :share% of the total excl. VAT of quote :number (benchmark: :threshold%).', 'question' => 'Can you detail what this lump sum includes?'],
            'missing_insurance' => ['message' => 'No professional insurance is mentioned.', 'reason' => 'The business identity copied into quote :number mentions no insurer.', 'question' => 'Are you insured for this work? Can you send me your certificate?'],
            'validity_ending' => ['message' => 'This quote expires soon.', 'reason' => 'Quote :number is valid until :date, in :days day(s).', 'question' => 'Can you extend the validity of this quote?'],
            'validity_expired' => ['message' => 'This quote is no longer valid.', 'reason' => 'Quote :number expired on :date.', 'question' => 'Can you send me a new version of this quote?'],
            'price_gap_above' => ['message' => 'This quote is markedly more expensive than the median of the quotes received.', 'reason' => 'Total incl. VAT of quote :number: :amount, :gap% above the median (:median) of the :count quotes compared (benchmark: :threshold%).', 'question' => 'What explains this price difference?'],
            'price_gap_below' => ['message' => 'This quote is markedly cheaper than the median of the quotes received.', 'reason' => 'Total incl. VAT of quote :number: :amount, :gap% below the median (:median) of the :count quotes compared (benchmark: :threshold%).', 'question' => 'Are all the items of the other quotes included in your price?'],
            'missing_line_type' => ['message' => 'An item found in most other quotes is missing.', 'reason' => ':present of :others other quotes have a ":type" line, quote :number does not.', 'question' => 'Is ":type" included in your quote?'],
            'mixed_units' => ['message' => 'The same item is counted in different units: compare totals rather than unit prices.', 'reason' => '":type" is counted in :units depending on the quote.'],
            'earliest_start' => ['message' => 'This quote offers the earliest start date.', 'reason' => 'Quote :number offers to start on :date, the earliest among the quotes that give one.'],
        ],
    ];

    /**
     * @var list<Finding>
     */
    private array $findings = [];

    private string $locale = 'fr';

    public function __construct(private GetProviderSummaries $summaries) {}

    /**
     * @param  Collection<int, Quote>  $quotes  devis du projet, lignes chargées, ordre de réception
     * @param  bool  $onSite  le projet se réalise sur place (projet sans travail à distance)
     * @return array{quotes: list<array<string, mixed>>, findings: list<Finding>}
     */
    public function build(Collection $quotes, bool $onSite, string $locale): array
    {
        $this->findings = [];
        $this->locale = $locale === 'en' ? 'en' : 'fr';

        foreach ($quotes as $quote) {
            $this->quoteRules($quote, $onSite);
        }

        $this->priceGap($quotes);
        $this->missingLineTypes($quotes);
        $this->mixedUnits($quotes);
        $this->earliestStart($quotes);

        $summaries = $this->summaries->handle(array_values(array_filter($quotes->pluck('provider_id')->all(), fn (mixed $id): bool => is_int($id))));

        return [
            'quotes' => $quotes->map(fn (Quote $quote): array => self::column($quote, $quote->provider_id === null ? null : ($summaries[$quote->provider_id] ?? null)))->values()->all(),
            'findings' => $this->findings,
        ];
    }

    private function quoteRules(Quote $quote, bool $onSite): void
    {
        $number = ['number' => (string) $quote->number];

        if ($quote->start_date === null) {
            $this->add('missing_start_date', 'attention', $quote, $number);
        }

        if ($quote->duration === null) {
            $this->add('missing_duration', 'attention', $quote, $number);
        }

        if ($quote->payment_terms === null) {
            $this->add('missing_payment_terms', 'attention', $quote, $number);
        }

        if ($quote->deposit_cents !== null) {
            $isEarnest = $quote->deposit_kind?->value === 'arrhes';
            $kind = $this->locale === 'en' ? ($isEarnest ? 'earnest money' : 'deposit') : ($isEarnest ? 'arrhes' : 'acompte');
            $article = $this->locale === 'en' ? $kind : ($isEarnest ? 'd\'arrhes' : 'd\'acompte');
            $this->add('deposit_requested', 'info', $quote, [...$number, 'kind' => $kind, 'kind_article' => $article, 'amount' => $this->money($quote, (int) $quote->deposit_cents)]);
        }

        if ($onSite && ! $quote->lines->contains(fn (QuoteLine $line): bool => $line->line_type === QuoteLineType::Travel)) {
            $this->add('missing_travel_costs', 'attention', $quote, $number);
        }

        $threshold = (int) $this->setting('single_lump_sum', 'share_percent', 80);

        if ($quote->total_ht_cents > 0) {
            foreach ($quote->lines as $line) {
                $share = intdiv($line->total_ht_cents * 100, $quote->total_ht_cents);

                if ($line->unit === QuoteUnit::Lump && $share >= $threshold) {
                    $this->add('single_lump_sum', 'attention', $quote, [...$number, 'designation' => $line->designation, 'share' => (string) $share, 'threshold' => (string) $threshold]);
                    break;
                }
            }
        }

        if (blank($quote->issuer_snapshot['insurer_name'] ?? null)) {
            $this->add('missing_insurance', 'attention', $quote, $number);
        }

        if ($quote->expires_at !== null && $quote->valid_until !== null) {
            $date = $this->day($quote->valid_until);

            if ($quote->expires_at->isPast()) {
                $this->add('validity_expired', 'attention', $quote, [...$number, 'date' => $date], 'validity_ending');
            } else {
                $days = (int) CarbonImmutable::now()->startOfDay()->diffInDays($quote->expires_at->copy()->startOfDay());

                if ($days <= (int) $this->setting('validity_ending', 'days', 7)) {
                    $this->add('validity_ending', 'info', $quote, [...$number, 'date' => $date, 'days' => (string) $days]);
                }
            }
        }
    }

    /**
     * Écart du total TTC à la médiane des devis comparés (à partir de min_quotes devis).
     *
     * @param  Collection<int, Quote>  $quotes
     */
    private function priceGap(Collection $quotes): void
    {
        if ($quotes->count() < (int) $this->setting('price_gap', 'min_quotes', 3)) {
            return;
        }

        $totals = $quotes->pluck('total_ttc_cents')->map(fn (mixed $total): int => (int) $total)->sort()->values()->all();
        $count = count($totals);
        $median = $count % 2 === 1 ? $totals[intdiv($count, 2)] : intdiv($totals[$count / 2 - 1] + $totals[$count / 2], 2);
        $threshold = (int) $this->setting('price_gap', 'gap_percent', 30);

        if ($median <= 0) {
            return;
        }

        foreach ($quotes as $quote) {
            $gap = intdiv(abs($quote->total_ttc_cents - $median) * 100, $median);

            if ($gap >= $threshold) {
                $this->add($quote->total_ttc_cents > $median ? 'price_gap_above' : 'price_gap_below', 'info', $quote, [
                    'number' => (string) $quote->number,
                    'amount' => $this->money($quote, $quote->total_ttc_cents),
                    'median' => $this->money($quote, $median),
                    'gap' => (string) $gap,
                    'count' => (string) $count,
                    'threshold' => (string) $threshold,
                ], 'price_gap');
            }
        }
    }

    /**
     * Type de ligne présent dans plus de la moitié des autres devis, absent de celui-ci.
     *
     * @param  Collection<int, Quote>  $quotes
     */
    private function missingLineTypes(Collection $quotes): void
    {
        if ($quotes->count() < (int) $this->setting('missing_line_type', 'min_quotes', 3)) {
            return;
        }

        $typesByQuote = $quotes->mapWithKeys(fn (Quote $quote): array => [$quote->uuid => $quote->lines->map(fn (QuoteLine $line): string => $line->line_type->value)->unique()->values()->all()]);

        foreach ($quotes as $quote) {
            $others = $typesByQuote->except([$quote->uuid]);

            foreach (QuoteLineType::cases() as $type) {
                if (in_array($type->value, $typesByQuote[$quote->uuid], true) || $type === QuoteLineType::Other) {
                    continue;
                }

                $present = $others->filter(fn (array $types): bool => in_array($type->value, $types, true))->count();

                if ($present * 2 > $others->count()) {
                    $this->add('missing_line_type', 'attention', $quote, [
                        'number' => (string) $quote->number,
                        'type' => $type->label($this->locale),
                        'present' => (string) $present,
                        'others' => (string) $others->count(),
                    ]);
                }
            }
        }
    }

    /**
     * Un même type de ligne compté dans plusieurs unités selon les devis (constat global).
     *
     * @param  Collection<int, Quote>  $quotes
     */
    private function mixedUnits(Collection $quotes): void
    {
        if ($quotes->count() < 2) {
            return;
        }

        foreach (QuoteLineType::cases() as $type) {
            $units = $quotes
                ->flatMap(fn (Quote $quote): Collection => $quote->lines->filter(fn (QuoteLine $line): bool => $line->line_type === $type))
                ->map(fn (QuoteLine $line): string => $line->unit->value)
                ->unique()
                ->values();

            if ($units->count() > 1) {
                $this->add('mixed_units', 'info', null, [
                    'type' => $type->label($this->locale),
                    'units' => $units->map(fn (string $unit): string => QuoteUnit::from($unit)->label($this->locale))->implode(' / '),
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, Quote>  $quotes
     */
    private function earliestStart(Collection $quotes): void
    {
        $dated = $quotes->filter(fn (Quote $quote): bool => $quote->start_date !== null);

        if ($dated->count() < 2) {
            return;
        }

        /** @var Quote $earliest */
        $earliest = $dated->sortBy(fn (Quote $quote): string => (string) $quote->start_date?->toDateString())->first();
        $this->add('earliest_start', 'info', $earliest, ['number' => (string) $earliest->number, 'date' => $this->day($earliest->start_date)]);
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function add(string $textKey, string $severity, ?Quote $quote, array $replace, ?string $rule = null): void
    {
        $rule ??= $textKey;

        if (! (bool) config("marketplace.comparison.rules.{$rule}.enabled", false)) {
            return;
        }

        $texts = self::TEXTS[$this->locale][$textKey];
        $pairs = [];

        foreach ($replace as $key => $value) {
            $pairs[':'.$key] = $value;
        }

        $this->findings[] = [
            'rule' => $rule,
            'severity' => $severity,
            'quote' => $quote?->uuid,
            'message' => strtr($texts['message'], $pairs),
            'reason' => strtr($texts['reason'], $pairs),
            'question' => isset($texts['question']) ? strtr($texts['question'], $pairs) : null,
        ];
    }

    private function setting(string $rule, string $key, int $default): int
    {
        return (int) config("marketplace.comparison.rules.{$rule}.{$key}", $default);
    }

    private function money(Quote $quote, int $cents): string
    {
        return Formats::money($cents, $quote->currency, $this->locale);
    }

    private function day(?CarbonInterface $date): string
    {
        return $date === null ? '' : $date->format($this->locale === 'en' ? 'Y-m-d' : 'd/m/Y');
    }

    /**
     * Colonne d'un devis dans la comparaison : données du devis et résumé public du
     * prestataire, jamais d'identifiant interne.
     *
     * @param  array<string, mixed>|null  $provider
     * @return array<string, mixed>
     */
    private static function column(Quote $quote, ?array $provider): array
    {
        $lineTypes = [];

        foreach ($quote->lines as $line) {
            $type = $line->line_type->value;
            $lineTypes[$type] ??= ['count' => 0, 'total_ht_cents' => 0];
            $lineTypes[$type]['count']++;
            $lineTypes[$type]['total_ht_cents'] += $line->total_ht_cents;
        }

        return [
            'uuid' => $quote->uuid,
            'number' => $quote->number,
            'version' => $quote->version,
            'status' => $quote->status->value,
            'provider' => $provider ?? ['deleted' => true],
            'title' => $quote->title,
            'currency' => $quote->currency,
            'totals' => ['ht_cents' => $quote->total_ht_cents, 'vat_cents' => $quote->total_vat_cents, 'ttc_cents' => $quote->total_ttc_cents],
            'valid_until' => $quote->valid_until?->toDateString(),
            'expires_at' => $quote->expires_at?->toIso8601ZuluString(),
            'start_date' => $quote->start_date?->toDateString(),
            'duration' => $quote->duration,
            'payment_terms' => $quote->payment_terms,
            'deposit' => $quote->deposit_cents === null ? null : ['cents' => $quote->deposit_cents, 'kind' => $quote->deposit_kind?->value],
            'line_count' => $quote->lines->count(),
            'line_types' => $lineTypes,
            'insurance' => ! blank($quote->issuer_snapshot['insurer_name'] ?? null),
            'sent_at' => $quote->sent_at?->toIso8601ZuluString(),
        ];
    }
}
