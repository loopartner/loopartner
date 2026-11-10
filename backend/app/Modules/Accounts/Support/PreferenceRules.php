<?php

namespace App\Modules\Accounts\Support;

use App\Modules\Accounts\Rules\CountryCode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * Règles de validation des préférences, partagées par l'inscription et PATCH /me.
 */
final class PreferenceRules
{
    /** @var list<string> */
    public const LOCALES = ['fr', 'en'];

    /**
     * Toutes les préférences sont facultatives ; le pays peut être vidé avec null.
     *
     * @return array<string, list<string|ValidationRule|In>>
     */
    public static function rules(): array
    {
        return [
            'locale' => ['sometimes', 'required', 'string', Rule::in(self::LOCALES)],
            'timezone' => ['sometimes', 'required', 'string', 'max:64', 'timezone:all'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2', new CountryCode],
        ];
    }

    /**
     * Met le code pays en majuscules avant la validation ("fr" devient "FR").
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        if (isset($input['country']) && is_string($input['country'])) {
            $input['country'] = strtoupper(trim($input['country']));
        }

        return $input;
    }
}
