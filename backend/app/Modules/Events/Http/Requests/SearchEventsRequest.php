<?php

namespace App\Modules\Events\Http\Requests;

use App\Modules\Events\Enums\EventFormat;
use App\Modules\Events\Enums\EventType;
use App\Rules\ActiveServiceCategory;
use App\Rules\CountryCode;
use App\Rules\LanguageCode;
use App\Rules\NoInvisibleCharacters;
use App\Support\Pagination;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * GET /events : filtres de l'agenda public. Un paramètre vide est ignoré.
 *
 * - from / to (AAAA-MM-JJ, journées UTC) : événements qui se déroulent au moins en partie
 *   pendant la période (366 jours au plus) ;
 * - free : true/false, 1/0 ;
 * - q : recherche dans le titre, la description et la ville (2 à 100 caractères).
 */
class SearchEventsRequest extends FormRequest
{
    public const MAX_PERIOD_DAYS = 366;

    protected function prepareForValidation(): void
    {
        $input = $this->query();

        if (isset($input['country']) && is_string($input['country'])) {
            $input['country'] = strtoupper(trim($input['country']));
        }

        // Les paramètres d'URL sont des chaînes : "true" et "false" sont acceptés comme booléens.
        if (isset($input['free']) && is_string($input['free'])) {
            $input['free'] = match (strtolower($input['free'])) {
                'true' => '1',
                'false' => '0',
                default => $input['free'],
            };
        }

        $this->replace($input);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'type' => ['nullable', Rule::enum(EventType::class)],
            'format' => ['nullable', Rule::enum(EventFormat::class)],
            'country' => ['nullable', 'string', 'size:2', new CountryCode],
            'city' => ['nullable', 'string', 'max:100', new NoInvisibleCharacters],
            'category' => ['nullable', 'string', 'max:100', new ActiveServiceCategory],
            'language' => ['nullable', 'string', new LanguageCode],
            'free' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'min:2', 'max:100', new NoInvisibleCharacters],
            ...Pagination::rules(),
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $from = $this->query('from');
                $to = $this->query('to');

                if ($validator->errors()->isEmpty() && is_string($from) && is_string($to)
                    && CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > self::MAX_PERIOD_DAYS) {
                    $validator->errors()->add('to', 'La période recherchée dépasse '.self::MAX_PERIOD_DAYS.' jours.');
                }
            },
        ];
    }
}
