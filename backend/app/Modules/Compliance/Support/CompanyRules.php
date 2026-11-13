<?php

namespace App\Modules\Compliance\Support;

use App\Modules\Compliance\Enums\ActivityType;
use App\Modules\Compliance\Enums\ComplianceOption;
use App\Modules\Compliance\Enums\VatRegime;
use App\Rules\CountryCode;
use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Règles de validation du profil d'une entreprise (questionnaire de démarrage et
 * modification). La compatibilité avec les règles du pays est vérifiée ensuite par
 * CompanyProfile.
 *
 * - Numéro d'entreprise et numéro de TVA : données privées, chiffrées en base, jamais publiques.
 *   Format vérifié (France : SIREN 9 chiffres ou SIRET 14 chiffres ; TVA « FR » + 11
 *   caractères), espaces retirés.
 * - Date de création : au plus un an dans le futur (entreprise en projet).
 */
final class CompanyRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(bool $partial, string $country): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $text = ['string', new NoHtml, new NoInvisibleCharacters];

        return [
            'name' => [$required, ...$text, 'min:1', 'max:120'],
            'country' => $partial ? ['prohibited'] : ['required', 'string', new CountryCode],
            'legal_form' => [$required, 'string', 'max:100'],
            'activity_types' => [$required, 'array', 'min:1', 'max:2'],
            'activity_types.*' => ['string', Rule::in(ActivityType::values()), 'distinct'],
            'vat_regime' => [$required, 'string', Rule::in(VatRegime::values())],
            'created_on' => [$required, 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.now()->addYear()->toDateString()],
            'fiscal_year_end' => ['sometimes', 'string', 'regex:/^(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$/', self::validMonthDay()],
            'options' => ['sometimes', 'array:'.implode(',', ComplianceOption::values())],
            'options.*' => [self::optionValue()],
            'registration_number' => ['sometimes', 'nullable', 'string', 'max:40', self::registrationNumber($country)],
            'vat_number' => ['sometimes', 'nullable', 'string', 'max:40', self::vatNumber($country)],
            ...collect(['uuid', 'id', 'user_id', 'calendar_refreshed_at', 'calendar_changed_at', 'created_at', 'updated_at', 'locked'])
                ->mapWithKeys(fn (string $field): array => [$field => ['prohibited']])
                ->all(),
        ];
    }

    /**
     * Numéro sans espaces, points ni tirets, en majuscules.
     */
    public static function compact(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $compact = strtoupper((string) preg_replace('/[\s.\-]/u', '', $value));

        return $compact === '' ? null : $compact;
    }

    private static function registrationNumber(string $country): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($country): void {
            $number = self::compact(is_string($value) ? $value : null);
            $valid = match ($country) {
                'FR' => $number !== null && preg_match('/^(\d{9}|\d{14})$/', $number) === 1,
                default => $number !== null && preg_match('/^[A-Z0-9]{4,30}$/', $number) === 1,
            };

            if (! $valid) {
                $fail($country === 'FR'
                    ? 'Le numéro d\'entreprise doit être un SIREN (9 chiffres) ou un SIRET (14 chiffres).'
                    : 'Le numéro d\'entreprise n\'est pas valide.');
            }
        };
    }

    private static function vatNumber(string $country): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($country): void {
            $number = self::compact(is_string($value) ? $value : null);
            $valid = match ($country) {
                'FR' => $number !== null && preg_match('/^FR[0-9A-Z]{2}\d{9}$/', $number) === 1,
                default => $number !== null && preg_match('/^[A-Z]{2}[0-9A-Z]{2,13}$/', $number) === 1,
            };

            if (! $valid) {
                $fail('Le numéro de TVA intracommunautaire n\'est pas valide.');
            }
        };
    }

    private static function validMonthDay(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            [$month, $day] = array_map('intval', explode('-', (string) $value) + [1 => '0']);

            // Année bissextile : le 29 février est une date de clôture possible.
            if (! checkdate($month, $day, 2024)) {
                $fail('La date de clôture de l\'exercice n\'existe pas.');
            }
        };
    }

    private static function optionValue(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $option = ComplianceOption::tryFrom((string) substr($attribute, strlen('options.')));

            if ($option === null || ! $option->isValid($value)) {
                $fail('La valeur de cette option n\'est pas valide.');
            }
        };
    }
}
