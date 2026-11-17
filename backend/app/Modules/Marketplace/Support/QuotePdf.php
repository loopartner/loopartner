<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Marketplace\Enums\ClientKind;
use App\Modules\Marketplace\Enums\QuoteStatus;
use App\Modules\Marketplace\Enums\QuoteUnit;
use App\Modules\Marketplace\Models\Quote;
use App\Modules\Marketplace\Models\QuoteLine;

/**
 * PDF d'un devis envoyé (décision 43 : gratuit pour les deux parties), construit uniquement à
 * partir du contenu figé du devis : identité de l'émetteur, bloc client, lignes, taux copiés,
 * totaux, conditions, mentions et preuve d'acceptation. Aucune donnée d'un autre utilisateur.
 *
 * Personnalisation Premium (marketplace.quote_branding), figée à l'envoi : mentions
 * personnalisées (issuer_snapshot.branding) et copie du logo (issuer_logo, décision 51). Le PDF
 * ne relit jamais le profil : changer ou supprimer son logo, ou perdre le Premium, ne change pas
 * un devis envoyé. Un devis envoyé avant la décision 51 n'a pas de copie et sort sans logo.
 *
 * Tout texte vient du devis et est échappé : le PDF ne contient aucun HTML d'utilisateur.
 */
final class QuotePdf
{
    /**
     * @var array<string, array<string, string>>
     */
    private const LABELS = [
        'fr' => [
            'quote' => 'Devis', 'version' => 'version', 'issued' => 'Établi le', 'valid' => 'Valable jusqu\'au',
            'client' => 'Client', 'place' => 'Lieu d\'exécution', 'subject' => 'Objet', 'designation' => 'Désignation',
            'quantity' => 'Quantité', 'unit' => 'Unité', 'unit_price' => 'Prix unitaire HT', 'vat' => 'TVA',
            'total_ht' => 'Total HT', 'total_vat' => 'TVA', 'total_ttc' => 'Total TTC', 'conditions' => 'Conditions',
            'start' => 'Date de début prévue', 'duration' => 'Durée prévue', 'payment' => 'Conditions de paiement',
            'deposit' => 'Somme versée à la commande', 'fee_free' => 'Devis gratuit', 'fee_paid' => 'Devis payant',
            'parts' => 'Conservation des pièces remplacées proposée au client', 'yes' => 'oui', 'no' => 'non',
            'notes' => 'Remarques', 'insurance' => 'Assurance professionnelle', 'coverage' => 'couverture',
            'ten_year' => 'Assurance décennale souscrite : l\'attestation est remise par le prestataire avec ce devis.',
            'mediator' => 'Médiateur de la consommation', 'profession' => 'Profession réglementée', 'terms' => 'Conditions générales de vente',
            'withdrawal' => 'Droit de rétractation : :days jours à compter de l\'acceptation, exerçable gratuitement en ligne depuis votre espace Loopartner.',
            'express' => 'Le client a demandé l\'exécution de la prestation avant la fin du délai de rétractation.',
            'accepted' => 'Devis accepté en ligne par le client le :date.', 'certified' => 'Le client a certifié :',
            'withdrawn' => 'Le client s\'est rétracté le :date.', 'status' => 'Statut', 'fingerprint' => 'Empreinte du contenu (SHA-256)',
            'not_party' => 'Loopartner met en relation les parties et fournit l\'outil de devis, mais n\'est pas partie au contrat conclu entre le prestataire et le client.',
            'phone' => 'Tél.', 'email' => 'Email', 'siren' => 'SIREN', 'vat_number' => 'N° TVA', 'capital' => 'Capital',
            'acompte' => 'acompte', 'arrhes' => 'arrhes',
        ],
        'en' => [
            'quote' => 'Quote', 'version' => 'version', 'issued' => 'Issued on', 'valid' => 'Valid until',
            'client' => 'Client', 'place' => 'Place of performance', 'subject' => 'Subject', 'designation' => 'Description',
            'quantity' => 'Quantity', 'unit' => 'Unit', 'unit_price' => 'Unit price excl. VAT', 'vat' => 'VAT',
            'total_ht' => 'Total excl. VAT', 'total_vat' => 'VAT', 'total_ttc' => 'Total incl. VAT', 'conditions' => 'Terms',
            'start' => 'Planned start date', 'duration' => 'Planned duration', 'payment' => 'Payment terms',
            'deposit' => 'Amount paid on order', 'fee_free' => 'Free quote', 'fee_paid' => 'Paid quote',
            'parts' => 'Return of replaced parts offered to the client', 'yes' => 'yes', 'no' => 'no',
            'notes' => 'Notes', 'insurance' => 'Professional insurance', 'coverage' => 'coverage',
            'ten_year' => 'Ten-year liability insurance taken out: the certificate is provided by the provider with this quote.',
            'mediator' => 'Consumer mediator', 'profession' => 'Regulated profession', 'terms' => 'Terms of sale',
            'withdrawal' => 'Right of withdrawal: :days days from acceptance, free of charge online from your Loopartner account.',
            'express' => 'The client asked for the service to start before the end of the withdrawal period.',
            'accepted' => 'Quote accepted online by the client on :date.', 'certified' => 'The client certified:',
            'withdrawn' => 'The client withdrew on :date.', 'status' => 'Status', 'fingerprint' => 'Content fingerprint (SHA-256)',
            'not_party' => 'Loopartner connects the parties and provides the quote tool, but is not a party to the contract between the provider and the client.',
            'phone' => 'Phone', 'email' => 'Email', 'siren' => 'SIREN', 'vat_number' => 'VAT no.', 'capital' => 'Share capital',
            'acompte' => 'deposit', 'arrhes' => 'earnest money',
        ],
    ];

    public function render(Quote $quote, string $locale): string
    {
        return Pdf::fromHtml($this->html($quote, $locale === 'en' ? 'en' : 'fr'));
    }

    public static function filename(Quote $quote): string
    {
        return 'devis-'.($quote->number ?? 'brouillon').'-v'.$quote->version.'.pdf';
    }

    /**
     * Document HTML du PDF (public pour les tests : le texte d'un PDF est compressé).
     */
    public function html(Quote $quote, string $locale): string
    {
        $t = self::LABELS[$locale];
        $issuer = $quote->issuer_snapshot?->getArrayCopy() ?? [];
        $client = $quote->client_snapshot->getArrayCopy();
        $branding = is_array($issuer['branding'] ?? null) ? $issuer['branding'] : [];
        $timezone = (string) config('marketplace.countries.'.($issuer['country'] ?? 'FR').'.timezone', 'UTC');
        $money = fn (int $cents): string => Formats::money($cents, $quote->currency, $locale);
        $e = fn (mixed $value): string => e((string) $value);

        $logo = ($branding['logo'] ?? false) && $quote->issuer_logo !== null ? 'data:image/png;base64,'.$quote->issuer_logo : null;

        $html = '<!doctype html><html><head><meta charset="utf-8"><style>'
            .'body{font-family:"DejaVu Sans",sans-serif;font-size:9pt;color:#222}h1{font-size:15pt;margin:0 0 4pt}'
            .'h2{font-size:10pt;margin:10pt 0 3pt;border-bottom:1px solid #999}table{width:100%;border-collapse:collapse}'
            .'th,td{padding:3pt;vertical-align:top}.lines th{background:#eee;text-align:left}.lines td{border-bottom:1px solid #ddd}'
            .'.num{text-align:right;white-space:nowrap}.small{font-size:7.5pt;color:#555}.totals td{padding:2pt 3pt}'
            .'</style></head><body>';

        $html .= '<table><tr><td style="width:60%">';
        $html .= $logo !== null ? '<img src="'.$logo.'" style="max-height:60pt;max-width:160pt"><br>' : '';
        $html .= '<strong>'.$e($issuer['legal_name'] ?? '').(($issuer['individual'] ?? false) ? ' EI' : '').'</strong><br>';
        $html .= ($issuer['trade_name'] ?? null) !== null ? $e($issuer['trade_name']).'<br>' : '';
        $html .= $e($issuer['legal_form_label'][$locale] ?? '').'<br>';
        $html .= $e($issuer['address_line1'] ?? '').'<br>'.(($issuer['address_line2'] ?? null) !== null ? $e($issuer['address_line2']).'<br>' : '');
        $html .= $e(($issuer['postal_code'] ?? '').' '.($issuer['city'] ?? '').' ('.($issuer['country'] ?? '').')').'<br>';
        $html .= $t['phone'].' '.$e($issuer['phone'] ?? '').' · '.$t['email'].' '.$e($issuer['email'] ?? '').'<br>';
        $html .= $t['siren'].' '.$e($issuer['registration_number'] ?? '');
        $html .= ($issuer['registry'] ?? '') === 'rcs' ? ' · RCS '.$e($issuer['registry_city'] ?? '') : ' · RNE';
        $html .= ($issuer['share_capital_cents'] ?? null) !== null ? ' · '.$t['capital'].' '.$money((int) $issuer['share_capital_cents']) : '';
        $html .= ($issuer['vat_number'] ?? null) !== null ? '<br>'.$t['vat_number'].' '.$e($issuer['vat_number']) : '';
        $html .= '</td><td style="width:40%">';
        $html .= '<h1>'.$t['quote'].' '.$e($quote->number).'</h1>'.$t['version'].' '.$quote->version.'<br>';
        $html .= $t['issued'].' '.Formats::date($quote->sent_at, $timezone, $locale).'<br>';
        $html .= $t['valid'].' '.$e($quote->valid_until === null ? '' : ($locale === 'en' ? $quote->valid_until->format('Y-m-d') : $quote->valid_until->format('d/m/Y'))).'<br>';
        $html .= $t['status'].' : '.$e($quote->status->value).'<br><br>';
        $html .= '<strong>'.$t['client'].'</strong><br>'.$e($client['name'] ?? '').'<br>';
        $html .= ($client['company_name'] ?? null) !== null ? $e($client['company_name']).'<br>' : '';
        $html .= ($client['registration_number'] ?? null) !== null ? $t['siren'].' '.$e($client['registration_number']).'<br>' : '';
        $html .= ($client['address'] ?? null) !== null ? $e($client['address']).'<br>' : '';
        $html .= $t['place'].' : '.$e($client['execution_place'] ?? '');
        $html .= '</td></tr></table>';

        $html .= '<h2>'.$t['subject'].'</h2><strong>'.$e($quote->title).'</strong><br>'.nl2br($e($quote->scope));

        $html .= '<h2>'.$t['designation'].'</h2><table class="lines"><tr><th>'.$t['designation'].'</th><th class="num">'.$t['quantity'].'</th>'
            .'<th>'.$t['unit'].'</th><th class="num">'.$t['unit_price'].'</th><th class="num">'.$t['vat'].'</th><th class="num">'.$t['total_ht'].'</th></tr>';

        foreach ($quote->lines as $line) {
            /** @var QuoteLine $line */
            $html .= '<tr><td>'.$e($line->designation).(($line->details !== null) ? '<br><span class="small">'.nl2br($e($line->details)).'</span>' : '').'</td>'
                .'<td class="num">'.Formats::quantity($line->quantity_milli, $locale).'</td>'
                .'<td>'.$e(QuoteUnit::from($line->unit->value)->label($locale)).'</td>'
                .'<td class="num">'.$money($line->unit_price_cents).'</td>'
                .'<td class="num">'.Formats::rate($line->vat_basis_points, $locale).'</td>'
                .'<td class="num">'.$money($line->total_ht_cents).'</td></tr>';
        }

        $html .= '</table><table class="totals" style="width:50%;margin-left:50%;margin-top:6pt">';
        $html .= '<tr><td>'.$t['total_ht'].'</td><td class="num">'.$money($quote->total_ht_cents).'</td></tr>';

        foreach ($quote->vat_breakdown ?? [] as $row) {
            $html .= '<tr><td>'.$t['total_vat'].' '.Formats::rate((int) $row['basis_points'], $locale).'</td><td class="num">'.$money((int) $row['vat_cents']).'</td></tr>';
        }

        $html .= '<tr><td><strong>'.$t['total_ttc'].'</strong></td><td class="num"><strong>'.$money($quote->total_ttc_cents).'</strong></td></tr></table>';

        foreach ($this->vatMentions($quote, $locale) as $mention) {
            $html .= '<p class="small">'.$e($mention).'</p>';
        }

        $html .= '<h2>'.$t['conditions'].'</h2>';
        $conditions = [];
        $conditions[] = $quote->start_date !== null ? $t['start'].' : '.($locale === 'en' ? $quote->start_date->format('Y-m-d') : $quote->start_date->format('d/m/Y')) : null;
        $conditions[] = $quote->duration !== null ? $t['duration'].' : '.$e($quote->duration) : null;
        $conditions[] = $quote->payment_terms !== null ? $t['payment'].' : '.$e($quote->payment_terms) : null;
        $conditions[] = $quote->deposit_cents !== null ? $t['deposit'].' : '.$money($quote->deposit_cents).' ('.$t[$quote->deposit_kind->value ?? 'acompte'].')' : null;
        $conditions[] = $quote->quote_is_free ? $t['fee_free'] : $t['fee_paid'].' : '.$money((int) $quote->quote_fee_cents);
        $conditions[] = $quote->keep_replaced_parts !== null ? $t['parts'].' : '.($quote->keep_replaced_parts ? $t['yes'] : $t['no']) : null;
        $conditions[] = $quote->notes !== null ? $t['notes'].' : '.nl2br($e($quote->notes)) : null;
        $html .= implode('<br>', array_filter($conditions));

        $html .= '<p class="small">';
        $html .= ($issuer['insurer_name'] ?? null) !== null
            ? $t['insurance'].' : '.$e($issuer['insurer_name']).', '.$e($issuer['insurer_contact'] ?? '').' ('.$t['coverage'].' : '.$e($issuer['insurance_coverage'] ?? '').')<br>'
            : '';
        $html .= ($issuer['insurance_ten_year'] ?? false) ? $t['ten_year'].'<br>' : '';
        $html .= ($issuer['mediator_name'] ?? null) !== null ? $t['mediator'].' : '.$e($issuer['mediator_name']).', '.$e($issuer['mediator_url'] ?? '').'<br>' : '';
        $html .= ($issuer['regulated_profession_title'] ?? null) !== null
            ? $t['profession'].' : '.$e($issuer['regulated_profession_title']).', '.$e($issuer['regulated_profession_state'] ?? '').', '.$e($issuer['regulated_profession_body'] ?? '').'<br>'
            : '';
        $html .= ($issuer['terms_url'] ?? null) !== null ? $t['terms'].' : '.$e($issuer['terms_url']).'<br>' : '';
        $html .= $quote->client_kind === ClientKind::Consumer ? strtr($t['withdrawal'], [':days' => (string) config('marketplace.withdrawal_days')]) : '';
        $html .= '</p>';

        if ($quote->status === QuoteStatus::Accepted && $quote->accepted_at !== null) {
            $html .= '<h2>'.strtr($t['accepted'], [':date' => Formats::date($quote->accepted_at, $timezone, $locale)]).'</h2>';
            $html .= ($quote->acceptance_data['express_execution'] ?? false) ? '<p>'.$t['express'].'</p>' : '';

            $certifications = $this->certificationTexts($quote, $locale);

            if ($certifications !== []) {
                $html .= '<p>'.$t['certified'].'<br>'.implode('<br>', array_map(fn (string $text): string => '– '.$e($text), $certifications)).'</p>';
            }

            $html .= $quote->withdrawal_exercised_at !== null ? '<p>'.strtr($t['withdrawn'], [':date' => Formats::date($quote->withdrawal_exercised_at, $timezone, $locale)]).'</p>' : '';
        }

        $html .= '<p class="small" style="margin-top:12pt">';
        $html .= is_string($branding['footer'] ?? null) ? nl2br($e($branding['footer'])).'<br>' : '';
        $html .= $t['fingerprint'].' : '.$e($quote->content_hash).'<br>'.$t['not_party'].'</p>';

        return $html.'</body></html>';
    }

    /**
     * @return list<string>
     */
    private function vatMentions(Quote $quote, string $locale): array
    {
        $mentions = [];

        foreach ($quote->lines as $line) {
            $mention = $line->vat_rate['mention'] ?? null;

            if (is_array($mention)) {
                $mentions[$line->vat_rate_code] = (string) ($mention[$locale] ?? $mention['fr'] ?? '');
            }
        }

        return array_values(array_filter($mentions));
    }

    /**
     * @return list<string>
     */
    private function certificationTexts(Quote $quote, string $locale): array
    {
        $texts = [];

        foreach ($quote->lines as $line) {
            $text = $line->vat_rate['client_certification'] ?? null;

            if (is_array($text)) {
                $texts[$line->vat_rate_code] = (string) ($text[$locale] ?? $text['fr'] ?? '');
            }
        }

        return array_values(array_filter($texts));
    }
}
