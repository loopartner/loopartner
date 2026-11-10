<?php

namespace App\Modules\Directory\Support;

use App\Rules\ActiveServiceCategory;
use App\Rules\CountryCode;
use App\Rules\CurrencyCode;
use App\Rules\NoHtml;
use Illuminate\Validation\ValidationException;

/**
 * Règles de validation d'un projet, partagées par la création (POST) et la modification
 * partielle (PATCH).
 */
final class ProjectRules
{
    public const BUDGET_MAX = 10_000_000;

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(bool $partial): array
    {
        // En PATCH, un champ absent reste inchangé ; un champ obligatoire présent ne peut pas être vide.
        $required = $partial ? ['sometimes', 'required'] : ['required'];
        $optional = $partial ? ['sometimes', 'nullable'] : ['nullable'];

        return [
            'title' => [...$required, 'string', 'min:5', 'max:150', new NoHtml],
            'description' => [...$required, 'string', 'min:20', 'max:5000', new NoHtml],
            'category' => [...$required, 'string', 'max:100', new ActiveServiceCategory],
            'budget_min' => [...$optional, 'integer', 'min:1', 'max:'.self::BUDGET_MAX],
            'budget_max' => [...$optional, 'integer', 'min:1', 'max:'.self::BUDGET_MAX],
            'currency' => ['sometimes', 'required', 'string', 'size:3', new CurrencyCode],
            'country' => [...$required, 'string', 'size:2', new CountryCode],
            'city' => [...$optional, 'string', 'max:100', new NoHtml],
            'remote_ok' => ['sometimes', 'required', 'boolean'],
            'desired_start_date' => [...$optional, 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }

    /**
     * Met les codes pays et devise en majuscules avant la validation.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        foreach (['country', 'currency'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $input[$field] = strtoupper(trim($input[$field]));
            }
        }

        return $input;
    }

    /**
     * Le budget minimal ne dépasse pas le budget maximal (égalité acceptée : budget fixe).
     * Appelée par les Actions sur les valeurs finales du projet.
     *
     * @throws ValidationException
     */
    public static function ensureBudgetOrder(?int $min, ?int $max): void
    {
        if ($min !== null && $max !== null && $min > $max) {
            throw ValidationException::withMessages([
                'budget_min' => 'Le budget minimal doit être inférieur ou égal au budget maximal.',
            ]);
        }
    }
}
