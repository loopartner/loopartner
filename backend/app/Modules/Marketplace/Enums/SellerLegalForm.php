<?php

namespace App\Modules\Marketplace\Enums;

/**
 * Forme juridique déclarée par le prestataire (mention du devis, Code de la consommation
 * R111-2). Liste fermée ; une société doit indiquer son capital.
 */
enum SellerLegalForm: string
{
    case IndividualEntrepreneur = 'ei';
    case MicroEntrepreneur = 'ei_micro';
    case Eurl = 'eurl';
    case Sarl = 'sarl';
    case Sasu = 'sasu';
    case Sas = 'sas';
    case Sa = 'sa';
    case Snc = 'snc';
    case Association = 'association';
    case Other = 'other';

    /**
     * Société commerciale : le capital social figure sur ses documents.
     */
    public function isCompany(): bool
    {
        return in_array($this, [self::Eurl, self::Sarl, self::Sasu, self::Sas, self::Sa, self::Snc], true);
    }

    /**
     * Entrepreneur individuel : « EI » suit son nom sur ses documents (Code de commerce R123-237).
     */
    public function isIndividual(): bool
    {
        return in_array($this, [self::IndividualEntrepreneur, self::MicroEntrepreneur], true);
    }

    public function label(string $locale): string
    {
        $labels = match ($this) {
            self::IndividualEntrepreneur => ['Entrepreneur individuel (EI)', 'Sole proprietorship (EI)'],
            self::MicroEntrepreneur => ['Entrepreneur individuel (EI), micro-entreprise', 'Sole proprietorship (EI), micro-enterprise'],
            self::Eurl => ['EURL', 'EURL (single-member limited company)'],
            self::Sarl => ['SARL', 'SARL (limited company)'],
            self::Sasu => ['SASU', 'SASU (single-member simplified joint-stock company)'],
            self::Sas => ['SAS', 'SAS (simplified joint-stock company)'],
            self::Sa => ['SA', 'SA (public limited company)'],
            self::Snc => ['SNC', 'SNC (general partnership)'],
            self::Association => ['Association', 'Association'],
            self::Other => ['Autre forme', 'Other legal form'],
        };

        return $locale === 'en' ? $labels[1] : $labels[0];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
