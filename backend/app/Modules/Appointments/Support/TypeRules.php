<?php

namespace App\Modules\Appointments\Support;

use App\Modules\Appointments\Enums\LocationMode;
use App\Modules\Appointments\Enums\TypeVisibility;
use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;
use App\Rules\SafeExternalUrl;
use App\Rules\SafeLinksInText;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validation d'un type de rendez-vous (création et modification partielle). Les champs fixés
 * par la plateforme sont refusés (prohibited, 422).
 *
 * Le détail du lieu dépend du mode, vérifié après fusion avec le type existant (location()) :
 * adresse obligatoire chez le prestataire, lien https sûr pour la visio (SafeExternalUrl), et
 * aucun détail quand le client le donne (chez lui, téléphone).
 */
final class TypeRules
{
    /**
     * @var list<string>
     */
    public const PROHIBITED = ['id', 'uuid', 'calendar_id', 'user_id', 'moderation_restricted_at', 'moderation_decision_uuid', 'created_at', 'updated_at'];

    /**
     * @return array<string, mixed>
     */
    public static function rules(bool $partial): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $durations = (array) config('appointments.durations');

        return [
            'title' => [$required, 'string', 'min:3', 'max:80', new NoHtml, new NoInvisibleCharacters],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000', new NoHtml, new NoInvisibleCharacters, new SafeLinksInText],
            'duration_minutes' => [$required, 'integer', 'min:'.$durations['min'], 'max:'.$durations['max'], 'multiple_of:'.$durations['step']],
            'location_mode' => [$required, 'string', Rule::enum(LocationMode::class)],
            'location_details' => ['sometimes', 'nullable', 'string', 'max:300', new NoInvisibleCharacters],
            'visibility' => ['sometimes', 'string', Rule::enum(TypeVisibility::class)],
            'auto_confirm' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:999'],
            ...array_fill_keys(self::PROHIBITED, ['prohibited']),
        ];
    }

    /**
     * Détail du lieu à enregistrer pour ce mode (null quand le client le donne).
     *
     * @throws ValidationException
     */
    public static function location(LocationMode $mode, ?string $details): ?string
    {
        if ($mode->detailsFromClient()) {
            return null;
        }

        $rule = $mode === LocationMode::Video
            ? ['required', 'string', new SafeExternalUrl]
            : ['required', 'string', new NoHtml];

        Validator::make(['location_details' => $details], ['location_details' => $rule], [
            'location_details.required' => $mode === LocationMode::Video
                ? 'Indiquez le lien de la visioconférence (https).'
                : 'Indiquez l\'adresse du rendez-vous.',
        ])->validate();

        return $details;
    }
}
