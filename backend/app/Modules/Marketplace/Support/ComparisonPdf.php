<?php

namespace App\Modules\Marketplace\Support;

/**
 * PDF de la comparaison des devis d'un projet (Premium, marketplace.quote_comparison_export) :
 * tableau côte à côte et constats avec leur raison. Construit à partir du résultat de
 * QuoteComparison ; tout texte est échappé.
 */
final class ComparisonPdf
{
    /**
     * @var array<string, string>
     */
    public const DISCLAIMER = [
        'fr' => 'Comparaison automatique fondée sur des règles explicables : elle ne constitue ni un avis sur la qualité des prestataires ni un conseil. Les prestataires sont présentés dans l\'ordre de réception de leurs devis.',
        'en' => 'Automatic comparison based on explainable rules: it is neither an opinion on the quality of the providers nor advice. Providers are listed in the order their quotes were received.',
    ];

    /**
     * @var array<string, array<string, string>>
     */
    private const LABELS = [
        'fr' => ['title' => 'Comparaison des devis', 'project' => 'Projet', 'provider' => 'Prestataire', 'quote' => 'Devis', 'ttc' => 'Total TTC', 'ht' => 'Total HT', 'valid' => 'Valable jusqu\'au', 'start' => 'Début', 'duration' => 'Durée', 'lines' => 'Lignes', 'insurance' => 'Assurance indiquée', 'findings' => 'Constats', 'all' => 'Tous les devis', 'yes' => 'oui', 'no' => 'non', 'question' => 'Question à poser'],
        'en' => ['title' => 'Quote comparison', 'project' => 'Project', 'provider' => 'Provider', 'quote' => 'Quote', 'ttc' => 'Total incl. VAT', 'ht' => 'Total excl. VAT', 'valid' => 'Valid until', 'start' => 'Start', 'duration' => 'Duration', 'lines' => 'Lines', 'insurance' => 'Insurance stated', 'findings' => 'Findings', 'all' => 'All quotes', 'yes' => 'yes', 'no' => 'no', 'question' => 'Question to ask'],
    ];

    /**
     * @param  array{quotes: list<array<string, mixed>>, findings: list<array{rule: string, severity: string, quote: string|null, message: string, reason: string, question: string|null}>}  $comparison
     */
    public static function render(array $comparison, string $projectTitle, string $locale): string
    {
        return Pdf::fromHtml(self::html($comparison, $projectTitle, $locale === 'en' ? 'en' : 'fr'));
    }

    /**
     * @param  array{quotes: list<array<string, mixed>>, findings: list<array{rule: string, severity: string, quote: string|null, message: string, reason: string, question: string|null}>}  $comparison
     */
    public static function html(array $comparison, string $projectTitle, string $locale): string
    {
        $t = self::LABELS[$locale];
        $e = fn (mixed $value): string => e((string) $value);
        $numbers = [];

        $html = '<!doctype html><html><head><meta charset="utf-8"><style>'
            .'body{font-family:"DejaVu Sans",sans-serif;font-size:8.5pt;color:#222}h1{font-size:14pt}table{width:100%;border-collapse:collapse}'
            .'th,td{border:1px solid #ccc;padding:3pt;vertical-align:top}th{background:#eee;text-align:left}.small{font-size:7.5pt;color:#555}'
            .'</style></head><body>';
        $html .= '<h1>'.$t['title'].'</h1><p>'.$t['project'].' : '.$e($projectTitle).'</p>';
        $html .= '<table><tr><th></th>';

        foreach ($comparison['quotes'] as $quote) {
            $numbers[$quote['uuid']] = $quote['number'].' v'.$quote['version'];
            $html .= '<th>'.$e($quote['provider']['display_name'] ?? '—').'<br><span class="small">'.$t['quote'].' '.$e($numbers[$quote['uuid']]).'</span></th>';
        }

        $html .= '</tr>';
        $rows = [
            $t['ttc'] => fn (array $q): string => Formats::money((int) $q['totals']['ttc_cents'], (string) $q['currency'], $locale),
            $t['ht'] => fn (array $q): string => Formats::money((int) $q['totals']['ht_cents'], (string) $q['currency'], $locale),
            $t['valid'] => fn (array $q): string => (string) ($q['valid_until'] ?? ''),
            $t['start'] => fn (array $q): string => (string) ($q['start_date'] ?? '—'),
            $t['duration'] => fn (array $q): string => (string) ($q['duration'] ?? '—'),
            $t['lines'] => fn (array $q): string => (string) $q['line_count'],
            $t['insurance'] => fn (array $q): string => $q['insurance'] ? $t['yes'] : $t['no'],
        ];

        foreach ($rows as $label => $value) {
            $html .= '<tr><th>'.$label.'</th>';

            foreach ($comparison['quotes'] as $quote) {
                $html .= '<td>'.$e($value($quote)).'</td>';
            }

            $html .= '</tr>';
        }

        $html .= '</table><h1>'.$t['findings'].'</h1><table>';

        foreach ($comparison['findings'] as $finding) {
            $html .= '<tr><td style="width:20%">'.$e($finding['quote'] === null ? $t['all'] : ($numbers[$finding['quote']] ?? '')).'</td><td>'
                .'<strong>'.$e($finding['message']).'</strong><br><span class="small">'.$e($finding['reason']).'</span>'
                .($finding['question'] !== null ? '<br>'.$t['question'].' : '.$e($finding['question']) : '')
                .'</td></tr>';
        }

        return $html.'</table><p class="small">'.$e(self::DISCLAIMER[$locale]).'</p></body></html>';
    }
}
