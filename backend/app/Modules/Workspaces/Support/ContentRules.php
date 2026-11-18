<?php

namespace App\Modules\Workspaces\Support;

use App\Rules\ActiveServiceCategory;
use App\Rules\CountryCode;
use App\Rules\CurrencyCode;
use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;
use Illuminate\Validation\ValidationException;

/**
 * Règles de validation du contenu d'un workspace (création et modification partielle).
 * Texte brut seulement (NoHtml, NoInvisibleCharacters). Les champs fixés par la plateforme
 * sont refusés (prohibited, 422) : statut, origine, marque IA, position (route d'ordre),
 * identifiants, dates du cycle de vie.
 */
final class ContentRules
{
    public const BUDGET_MAX = 10_000_000;

    /**
     * Champs fixés par la plateforme, refusés dans toute requête d'écriture.
     */
    private const PROHIBITED = ['uuid', 'id', 'user_id', 'workspace_id', 'status', 'source', 'ai_generated', 'ai_generated_at', 'position', 'created_at', 'updated_at'];

    /**
     * @return array<string, list<mixed>>
     */
    public static function workspace(bool $partial): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];
        $optional = $partial ? ['sometimes', 'nullable'] : ['nullable'];

        return [
            ...self::prohibited(['template_code', 'linked_project', 'linked_project_id', 'activated_at', 'archived_at', 'locked']),
            'title' => [...$required, 'string', 'min:3', 'max:'.self::length('title'), ...self::text()],
            'description' => [...$optional, 'string', 'max:'.self::length('description'), ...self::text()],
            'country' => [...$optional, 'string', 'size:2', new CountryCode],
            'budget_min' => [...$optional, 'integer', 'min:1', 'max:'.self::BUDGET_MAX],
            'budget_max' => [...$optional, 'integer', 'min:1', 'max:'.self::BUDGET_MAX],
            'currency' => ['sometimes', 'required', 'string', 'size:3', new CurrencyCode],
            'remote_ok' => [...$optional, 'boolean'],
            'desired_start_date' => [...$optional, 'date_format:Y-m-d'],
            'template' => $partial ? ['prohibited'] : ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function goal(bool $partial): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];
        $optional = $partial ? ['sometimes', 'nullable'] : ['nullable'];

        return [
            ...self::prohibited(['achieved_at', 'target']),
            'title' => [...$required, 'string', 'min:3', 'max:'.self::length('title'), ...self::text()],
            'description' => [...$optional, 'string', 'max:'.self::length('note'), ...self::text()],
            'target_date' => [...$optional, 'date_format:Y-m-d'],
            'achieved' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function step(bool $partial): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];
        $optional = $partial ? ['sometimes', 'nullable'] : ['nullable'];

        return [
            ...self::prohibited(['completed_at', 'service_category_id', 'tasks']),
            'title' => [...$required, 'string', 'min:3', 'max:'.self::length('title'), ...self::text()],
            'description' => [...$optional, 'string', 'max:'.self::length('note'), ...self::text()],
            'due_date' => [...$optional, 'date_format:Y-m-d'],
            'category' => [...$optional, 'string', 'max:100', new ActiveServiceCategory],
            'completed' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function task(bool $partial): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];
        $optional = $partial ? ['sometimes', 'nullable'] : ['nullable'];

        return [
            ...self::prohibited(['completed_at', 'step_id']),
            'title' => [...$required, 'string', 'min:2', 'max:'.self::length('title'), ...self::text()],
            'notes' => [...$optional, 'string', 'max:'.self::length('note'), ...self::text()],
            'due_date' => [...$optional, 'date_format:Y-m-d'],
            'completed' => ['sometimes', 'required', 'boolean'],
            // Déplacement vers une autre étape du même workspace (modification seulement).
            'step' => $partial ? ['sometimes', 'required', 'string', 'uuid'] : ['prohibited'],
        ];
    }

    /**
     * Représentation complète d'un cahier des charges (création et remplacement).
     *
     * @return array<string, list<mixed>>
     */
    public static function specification(): array
    {
        $rules = [
            ...self::prohibited(['service_category_id', 'step_id']),
            'title' => ['required', 'string', 'min:3', 'max:'.self::length('title'), ...self::text()],
            'category' => ['nullable', 'string', 'max:100', new ActiveServiceCategory],
            'step' => ['nullable', 'string', 'uuid'],
            'sections' => ['required', 'array:'.implode(',', SpecificationSections::KEYS)],
        ];

        foreach (SpecificationSections::KEYS as $key) {
            $rules['sections.'.$key] = ['nullable', 'string', 'max:'.self::length('section'), ...self::text()];
        }

        return $rules;
    }

    /**
     * Ordre complet d'une liste (uuids, chacun une fois).
     *
     * @return array<string, list<mixed>>
     */
    public static function order(): array
    {
        return [
            'order' => ['required', 'array', 'min:1', 'max:300'],
            'order.*' => ['required', 'string', 'uuid', 'distinct'],
        ];
    }

    /**
     * Le budget minimal ne dépasse pas le budget maximal.
     *
     * @throws ValidationException
     */
    public static function ensureBudgetOrder(?int $min, ?int $max): void
    {
        if ($min !== null && $max !== null && $min > $max) {
            throw ValidationException::withMessages(['budget_min' => 'Le budget minimal doit être inférieur ou égal au budget maximal.']);
        }
    }

    public static function length(string $key): int
    {
        return (int) config('workspaces.lengths.'.$key);
    }

    /**
     * @return list<object>
     */
    private static function text(): array
    {
        return [new NoHtml, new NoInvisibleCharacters];
    }

    /**
     * @param  list<string>  $extra
     * @return array<string, list<string>>
     */
    private static function prohibited(array $extra): array
    {
        return collect([...self::PROHIBITED, ...$extra])->mapWithKeys(fn (string $field): array => [$field => ['prohibited']])->all();
    }
}
