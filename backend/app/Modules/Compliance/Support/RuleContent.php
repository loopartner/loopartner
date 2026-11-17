<?php

namespace App\Modules\Compliance\Support;

use App\Modules\Compliance\Enums\ActivityType;
use App\Modules\Compliance\Enums\ComplianceOption;
use App\Modules\Compliance\Enums\RateCategory;
use App\Modules\Compliance\Enums\RuleKind;
use App\Modules\Compliance\Enums\ScheduleType;
use App\Modules\Compliance\Enums\VatRegime;
use App\Modules\Compliance\Models\ComplianceRule;
use App\Rules\ActiveServiceCategory;
use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;
use App\Rules\SafeExternalUrl;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Contenu d'une règle : validation (liste fermée de clés pour chaque type), reconstruction par
 * le serveur (jamais l'entrée brute) et liste des champs encore vides avant la vérification.
 *
 * Un brouillon peut laisser une valeur à null (« à compléter ») : seul le passage au statut
 * vérifié exige un contenu complet (missingFields()). Les montants sont en centimes et les taux
 * en points de base (2120 = 21,20 %), toujours entiers.
 */
final class RuleContent
{
    // Code d'une règle : « fr.vat.franchise-services » (pays en préfixe, minuscules).
    public const CODE_PATTERN = '/^[a-z]{2,4}(?:[._-][a-z0-9]+)+$/';

    private const KEY_PATTERN = '/^[a-z0-9]+(?:[_-][a-z0-9]+)*$/';

    // Plafond technique des montants (centimes) et des taux (points de base, 100 %).
    public const MAX_AMOUNT = 99_999_999_999;

    public const MAX_BASIS_POINTS = 10_000;

    /**
     * Règles de validation du contenu d'une règle de ce type.
     *
     * @return array<string, mixed>
     */
    public static function rules(RuleKind $kind, bool $partial): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            ...self::translatedRules('title', $required, true, 3, 200),
            ...self::translatedRules('summary', 'sometimes', false, 1, 2000),
            'conditions' => [$required, 'array:legal_forms,activity_types,vat_regimes,options'],
            'conditions.legal_forms' => ['sometimes', 'array', 'max:20'],
            'conditions.legal_forms.*' => ['string', 'max:100', 'regex:'.self::CODE_PATTERN, 'distinct'],
            'conditions.activity_types' => ['sometimes', 'array'],
            'conditions.activity_types.*' => ['string', Rule::in(ActivityType::values()), 'distinct'],
            'conditions.vat_regimes' => ['sometimes', 'array'],
            'conditions.vat_regimes.*' => ['string', Rule::in(VatRegime::values()), 'distinct'],
            'conditions.options' => ['sometimes', 'array:'.implode(',', ComplianceOption::values())],
            'conditions.options.*' => [self::optionValue()],
            'payload' => [$required, 'array:'.implode(',', self::payloadKeys($kind))],
            ...self::payloadRules($kind),
            'valid_from' => [$required, 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            // En modification, l'ordre des dates est vérifié par UpdateRule avec la date actuelle.
            'valid_until' => array_values(array_filter(['sometimes', 'nullable', 'date_format:Y-m-d', $partial ? null : 'after_or_equal:valid_from', 'before_or_equal:2100-12-31'])),
            'source_url' => ['sometimes', 'nullable', 'string', 'max:500', new SafeExternalUrl],
            'source_label' => ['sometimes', 'nullable', 'string', 'max:200', new NoHtml, new NoInvisibleCharacters],
            'internal_notes' => ['sometimes', 'nullable', 'string', 'max:10000', new NoHtml, new NoInvisibleCharacters],
        ];
    }

    /**
     * @return list<string>
     */
    private static function payloadKeys(RuleKind $kind): array
    {
        return match ($kind) {
            RuleKind::LegalForm => ['activity_types', 'vat_regimes', 'options'],
            RuleKind::Deadline => ['schedule', 'skip_creation_year', 'tasks', 'expects_document'],
            RuleKind::Threshold => ['amount', 'activity_types', 'prorate_first_year', 'consequence'],
            RuleKind::Rate => ['basis_points', 'activity_types', 'category'],
            RuleKind::Journey => ['steps'],
            RuleKind::VatRate => ['basis_points', 'label', 'scope', 'mention', 'client_certification', 'exclusive'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function payloadRules(RuleKind $kind): array
    {
        $activities = [
            'payload.activity_types' => ['sometimes', 'array'],
            'payload.activity_types.*' => ['string', Rule::in(ActivityType::values()), 'distinct'],
        ];

        return match ($kind) {
            RuleKind::LegalForm => [
                ...$activities,
                'payload.vat_regimes' => ['sometimes', 'array'],
                'payload.vat_regimes.*' => ['string', Rule::in(VatRegime::values()), 'distinct'],
                'payload.options' => ['sometimes', 'array'],
                'payload.options.*' => ['string', Rule::in(ComplianceOption::values()), 'distinct'],
            ],
            RuleKind::Deadline => [
                'payload.schedule' => ['required', 'array:type,month,day,months,dates,days,offset_months'],
                'payload.schedule.type' => ['required', 'string', Rule::in(ScheduleType::values())],
                'payload.schedule.month' => ['sometimes', 'nullable', 'integer', 'between:1,12'],
                'payload.schedule.day' => ['sometimes', 'nullable', 'integer', 'between:1,31'],
                'payload.schedule.months' => ['sometimes', 'nullable', 'array', 'max:12'],
                'payload.schedule.months.*' => ['integer', 'between:1,12', 'distinct'],
                'payload.schedule.dates' => ['sometimes', 'nullable', 'array', 'max:24'],
                'payload.schedule.dates.*' => ['date_format:Y-m-d', 'distinct'],
                'payload.schedule.days' => ['sometimes', 'nullable', 'integer', 'between:0,1000'],
                'payload.schedule.offset_months' => ['sometimes', 'nullable', 'integer', 'between:0,36'],
                'payload.skip_creation_year' => ['sometimes', 'boolean'],
                'payload.expects_document' => ['sometimes', 'boolean'],
                ...self::itemRules('payload.tasks', 'key,title,service_category'),
            ],
            RuleKind::Threshold => [
                ...$activities,
                'payload.amount' => ['sometimes', 'nullable', 'integer', 'between:0,'.self::MAX_AMOUNT],
                'payload.prorate_first_year' => ['sometimes', 'boolean'],
                ...self::translatedRules('payload.consequence', 'sometimes', false, 1, 2000),
            ],
            RuleKind::Rate => [
                ...$activities,
                'payload.basis_points' => ['sometimes', 'nullable', 'integer', 'between:0,'.self::MAX_BASIS_POINTS],
                'payload.category' => ['sometimes', 'string', Rule::in(RateCategory::values())],
            ],
            RuleKind::Journey => [
                ...self::itemRules('payload.steps', 'key,title,description,service_category,source_url'),
                ...self::translatedRules('payload.steps.*.description', 'sometimes', false, 1, 2000),
                'payload.steps.*.source_url' => ['sometimes', 'nullable', 'string', 'max:500', new SafeExternalUrl],
            ],
            // Taux de TVA d'une ligne de devis : libellé court, champ d'application rédigé par le
            // professionnel qui vérifie la règle, mention imprimée sur le devis (franchise,
            // autoliquidation…), texte certifié par le client à l'acceptation (taux réduits des
            // travaux dans les logements) ; exclusive : ne se combine avec aucun autre taux.
            RuleKind::VatRate => [
                'payload.basis_points' => ['sometimes', 'nullable', 'integer', 'between:0,'.self::MAX_BASIS_POINTS],
                ...self::translatedRules('payload.label', 'sometimes', false, 2, 100),
                ...self::translatedRules('payload.scope', 'sometimes', false, 1, 2000),
                ...self::translatedRules('payload.mention', 'sometimes', false, 1, 300),
                ...self::translatedRules('payload.client_certification', 'sometimes', false, 1, 1000),
                'payload.exclusive' => ['sometimes', 'boolean'],
            ],
        };
    }

    /**
     * Liste de tâches ou d'étapes : clé stable, titre traduit, catégorie de services facultative.
     *
     * @return array<string, mixed>
     */
    private static function itemRules(string $field, string $keys): array
    {
        return [
            $field => ['sometimes', 'array', 'max:30'],
            $field.'.*' => ['array:'.$keys],
            $field.'.*.key' => ['required', 'string', 'max:50', 'regex:'.self::KEY_PATTERN, 'distinct'],
            ...self::translatedRules($field.'.*.title', 'required', true, 3, 200),
            $field.'.*.service_category' => ['sometimes', 'nullable', 'string', 'max:100', new ActiveServiceCategory],
        ];
    }

    /**
     * Texte traduit {"fr": "…", "en": "…"} en texte brut.
     *
     * @return array<string, mixed>
     */
    private static function translatedRules(string $field, string $presence, bool $frenchRequired, int $min, int $max): array
    {
        $text = ['string', 'min:'.$min, 'max:'.$max, new NoHtml, new NoInvisibleCharacters];

        // Champ facultatif (modification partielle) : le français n'est exigé que si le champ est envoyé.
        $french = $presence === 'sometimes' ? 'required_with:'.$field : 'required';

        return [
            $field => $frenchRequired ? [$presence, 'array:fr,en'] : [$presence, 'nullable', 'array:fr,en'],
            $field.'.fr' => [$frenchRequired ? $french : 'nullable', ...$text],
            $field.'.en' => ['sometimes', 'nullable', ...$text],
        ];
    }

    /**
     * Valeur d'une option dans les conditions : booléen, ou choix de la liste fermée de l'option.
     */
    private static function optionValue(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $option = ComplianceOption::tryFrom((string) substr($attribute, strlen('conditions.options.')));

            if ($option === null || ! $option->isValid($value)) {
                $fail('La valeur de l\'option :attribute n\'est pas valide.');
            }
        };
    }

    /**
     * Contenu reconstruit à partir des données validées (création), ou fusionné avec le contenu
     * actuel (modification : seules les clés envoyées changent).
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed> attributs à enregistrer
     */
    public static function normalize(RuleKind $kind, array $validated, ?ComplianceRule $current = null): array
    {
        $attributes = [];

        if (array_key_exists('title', $validated)) {
            $attributes['title'] = self::translated($validated['title']);
        }

        if (array_key_exists('summary', $validated)) {
            $attributes['summary'] = $validated['summary'] === null ? null : self::translated($validated['summary']);
        }

        if (array_key_exists('conditions', $validated)) {
            $attributes['conditions'] = self::conditions(self::arrayOf($validated['conditions']));
        } elseif ($current === null) {
            $attributes['conditions'] = self::conditions([]);
        }

        if (array_key_exists('payload', $validated)) {
            $attributes['payload'] = self::payload($kind, self::arrayOf($validated['payload']));
        }

        foreach (['valid_from', 'valid_until', 'source_url', 'source_label', 'internal_notes'] as $field) {
            if (array_key_exists($field, $validated)) {
                $attributes[$field] = $validated[$field];
            }
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $conditions
     * @return array{legal_forms: list<string>, activity_types: list<string>, vat_regimes: list<string>, options: array<string, bool|string>}
     */
    public static function conditions(array $conditions): array
    {
        $options = [];

        foreach (ComplianceOption::cases() as $option) {
            $value = self::arrayOf($conditions['options'] ?? [])[$option->value] ?? null;

            if ($value !== null && $option->isValid($value)) {
                $options[$option->value] = $value;
            }
        }

        return [
            'legal_forms' => self::strings($conditions['legal_forms'] ?? []),
            'activity_types' => self::strings($conditions['activity_types'] ?? []),
            'vat_regimes' => self::strings($conditions['vat_regimes'] ?? []),
            'options' => $options,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function payload(RuleKind $kind, array $payload): array
    {
        return match ($kind) {
            RuleKind::LegalForm => [
                'activity_types' => self::strings($payload['activity_types'] ?? []),
                'vat_regimes' => self::strings($payload['vat_regimes'] ?? []),
                'options' => self::strings($payload['options'] ?? []),
            ],
            RuleKind::Deadline => [
                'schedule' => self::schedule(self::arrayOf($payload['schedule'] ?? [])),
                'skip_creation_year' => (bool) ($payload['skip_creation_year'] ?? false),
                'tasks' => self::items(self::arrayOf($payload['tasks'] ?? []), false),
                'expects_document' => (bool) ($payload['expects_document'] ?? false),
            ],
            RuleKind::Threshold => [
                'amount' => self::intOrNull($payload['amount'] ?? null),
                'activity_types' => self::strings($payload['activity_types'] ?? []),
                'prorate_first_year' => (bool) ($payload['prorate_first_year'] ?? false),
                'consequence' => isset($payload['consequence']) && is_array($payload['consequence']) ? self::translated($payload['consequence']) : null,
            ],
            RuleKind::Rate => [
                'basis_points' => self::intOrNull($payload['basis_points'] ?? null),
                'activity_types' => self::strings($payload['activity_types'] ?? []),
                'category' => is_string($payload['category'] ?? null) ? $payload['category'] : RateCategory::Other->value,
            ],
            RuleKind::Journey => [
                'steps' => self::items(self::arrayOf($payload['steps'] ?? []), true),
            ],
            RuleKind::VatRate => [
                'basis_points' => self::intOrNull($payload['basis_points'] ?? null),
                'label' => self::translatedOrNull($payload['label'] ?? null),
                'scope' => self::translatedOrNull($payload['scope'] ?? null),
                'mention' => self::translatedOrNull($payload['mention'] ?? null),
                'client_certification' => self::translatedOrNull($payload['client_certification'] ?? null),
                'exclusive' => (bool) ($payload['exclusive'] ?? false),
            ],
        };
    }

    /**
     * Récurrence : seules les clés utiles au type sont gardées, les autres sont à null.
     *
     * @param  array<string, mixed>  $schedule
     * @return array<string, mixed>
     */
    private static function schedule(array $schedule): array
    {
        $type = ScheduleType::tryFrom((string) ($schedule['type'] ?? ''));
        $used = match ($type) {
            ScheduleType::Yearly => ['month', 'day'],
            ScheduleType::Monthly => ['day'],
            ScheduleType::Quarterly => ['months', 'day'],
            ScheduleType::FixedDates => ['dates'],
            ScheduleType::AfterCreation => ['days'],
            ScheduleType::AfterFiscalYearEnd => ['offset_months', 'days'],
            null => [],
        };

        $normalized = ['type' => $type?->value];

        foreach (['month', 'day', 'days', 'offset_months'] as $key) {
            $normalized[$key] = in_array($key, $used, true) ? self::intOrNull($schedule[$key] ?? null) : null;
        }

        $months = array_map('intval', array_values(self::arrayOf($schedule['months'] ?? [])));
        sort($months);
        $dates = self::strings($schedule['dates'] ?? []);
        sort($dates);

        $normalized['months'] = in_array('months', $used, true) ? $months : null;
        $normalized['dates'] = in_array('dates', $used, true) ? $dates : null;

        return $normalized;
    }

    /**
     * Tâches d'une échéance ou étapes d'un parcours.
     *
     * @param  array<int|string, mixed>  $items
     * @return list<array<string, mixed>>
     */
    private static function items(array $items, bool $withDescription): array
    {
        $normalized = [];

        foreach ($items as $item) {
            $item = self::arrayOf($item);
            $entry = [
                'key' => (string) ($item['key'] ?? ''),
                'title' => self::translated(self::arrayOf($item['title'] ?? [])),
                'service_category' => is_string($item['service_category'] ?? null) ? $item['service_category'] : null,
            ];

            if ($withDescription) {
                $entry['description'] = isset($item['description']) && is_array($item['description']) ? self::translated($item['description']) : null;
                $entry['source_url'] = is_string($item['source_url'] ?? null) ? $item['source_url'] : null;
            }

            $normalized[] = $entry;
        }

        return $normalized;
    }

    /**
     * Champs encore vides qui empêchent la vérification (liste vide : règle complète).
     *
     * @return list<string>
     */
    public static function missingFields(ComplianceRule $rule): array
    {
        $missing = [];
        $payload = $rule->payload;

        if (blank($rule->source_url)) {
            $missing[] = 'source_url';
        }

        if (blank($rule->title['fr'] ?? null)) {
            $missing[] = 'title.fr';
        }

        $require = function (string $key, bool $present) use (&$missing): void {
            if (! $present) {
                $missing[] = 'payload.'.$key;
            }
        };

        switch ($rule->kind) {
            case RuleKind::LegalForm:
                $require('activity_types', self::arrayOf($payload['activity_types'] ?? []) !== []);
                $require('vat_regimes', self::arrayOf($payload['vat_regimes'] ?? []) !== []);
                break;

            case RuleKind::Deadline:
                $schedule = self::arrayOf($payload['schedule'] ?? []);
                $type = ScheduleType::tryFrom((string) ($schedule['type'] ?? ''));
                $require('schedule.type', $type !== null);
                $needs = match ($type) {
                    ScheduleType::Yearly => ['month', 'day'],
                    ScheduleType::Monthly => ['day'],
                    ScheduleType::Quarterly => ['months', 'day'],
                    ScheduleType::FixedDates => ['dates'],
                    ScheduleType::AfterCreation => ['days'],
                    ScheduleType::AfterFiscalYearEnd => ['offset_months', 'days'],
                    null => [],
                };

                foreach ($needs as $key) {
                    $value = $schedule[$key] ?? null;
                    $require('schedule.'.$key, is_array($value) ? $value !== [] : $value !== null);
                }
                break;

            case RuleKind::Threshold:
                $require('amount', ($payload['amount'] ?? null) !== null);
                $require('activity_types', self::arrayOf($payload['activity_types'] ?? []) !== []);
                break;

            case RuleKind::Rate:
                $require('basis_points', ($payload['basis_points'] ?? null) !== null);
                $require('activity_types', self::arrayOf($payload['activity_types'] ?? []) !== []);
                break;

            case RuleKind::Journey:
                $require('steps', self::arrayOf($payload['steps'] ?? []) !== []);
                break;

            case RuleKind::VatRate:
                $require('basis_points', ($payload['basis_points'] ?? null) !== null);
                $require('label.fr', filled(self::arrayOf($payload['label'] ?? [])['fr'] ?? null));
                $require('scope.fr', filled(self::arrayOf($payload['scope'] ?? [])['fr'] ?? null));
                break;
        }

        return $missing;
    }

    /**
     * @param  array<mixed>  $value
     * @return array{fr: string|null, en: string|null}
     */
    private static function translated(array $value): array
    {
        return [
            'fr' => is_string($value['fr'] ?? null) ? $value['fr'] : null,
            'en' => is_string($value['en'] ?? null) && $value['en'] !== '' ? $value['en'] : null,
        ];
    }

    /**
     * Texte traduit facultatif : null s'il est absent ou sans version française.
     *
     * @return array{fr: string|null, en: string|null}|null
     */
    private static function translatedOrNull(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $translated = self::translated($value);

        return $translated['fr'] === null ? null : $translated;
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function arrayOf(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return array_values(array_unique(array_filter(self::arrayOf($values), 'is_string')));
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }
}
