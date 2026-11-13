<?php

namespace App\Modules\Compliance\Enums;

/**
 * Options fiscales et sociales du questionnaire. Les options proposées pour un statut viennent
 * de sa règle (legal_form) ; les règles s'appliquent ou non selon leur valeur (conditions).
 *
 * Une option est un booléen, ou un choix dans une liste fermée (choices()).
 */
enum ComplianceOption: string
{
    // Versement libératoire de l'impôt sur le revenu (micro-entreprise).
    case IncomeTaxWithholding = 'income_tax_withholding';

    // Option pour l'impôt sur les sociétés (entreprise individuelle, EURL).
    case CorporateTaxOption = 'corporate_tax_option';

    // Périodicité des déclarations de chiffre d'affaires : mensuelle ou trimestrielle.
    case DeclarationFrequency = 'declaration_frequency';

    // L'entreprise emploie au moins un salarié.
    case HasEmployees = 'has_employees';

    // Aide à la création ou à la reprise d'entreprise (exonération de début d'activité).
    case Acre = 'acre';

    /**
     * Valeurs possibles d'une option à choix, ou null pour une option booléenne.
     *
     * @return list<string>|null
     */
    public function choices(): ?array
    {
        return match ($this) {
            self::DeclarationFrequency => ['monthly', 'quarterly'],
            default => null,
        };
    }

    /**
     * Valeur retenue quand l'utilisateur n'a pas répondu.
     */
    public function defaultValue(): bool|string
    {
        return match ($this) {
            self::DeclarationFrequency => 'quarterly',
            default => false,
        };
    }

    public function label(string $locale): string
    {
        return match ($this) {
            self::IncomeTaxWithholding => $locale === 'en' ? 'Income tax paid in instalments with contributions' : 'Versement libératoire de l\'impôt sur le revenu',
            self::CorporateTaxOption => $locale === 'en' ? 'Option for corporate tax' : 'Option pour l\'impôt sur les sociétés',
            self::DeclarationFrequency => $locale === 'en' ? 'Revenue declaration frequency' : 'Périodicité des déclarations de chiffre d\'affaires',
            self::HasEmployees => $locale === 'en' ? 'The company has employees' : 'L\'entreprise emploie des salariés',
            self::Acre => $locale === 'en' ? 'Business start-up relief (ACRE)' : 'Aide à la création ou à la reprise d\'entreprise (ACRE)',
        };
    }

    public function isValid(mixed $value): bool
    {
        $choices = $this->choices();

        return $choices === null ? is_bool($value) : (is_string($value) && in_array($value, $choices, true));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
