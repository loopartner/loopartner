<?php

namespace App\Modules\Events\Support;

use App\Models\User;
use App\Modules\Events\Enums\AddressVisibility;
use App\Modules\Events\Enums\EventFormat;
use App\Modules\Events\Enums\EventType;
use App\Rules\ActiveServiceCategory;
use App\Rules\CountryCode;
use App\Rules\LanguageCode;
use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;
use App\Rules\SafeExternalUrl;
use App\Rules\SafeLinksInText;
use Illuminate\Validation\Rule;

/**
 * Règles de validation d'un événement, partagées par la création (POST) et la modification
 * partielle (PATCH). La cohérence entre champs (format, gratuité, dates) est vérifiée ensuite
 * sur le contenu complet (EventContent).
 *
 * Les champs fixés par la plateforme (statut, slug, version, propriétaire, dates du cycle de
 * vie…) sont refusés (prohibited) : un organisateur ne publie jamais lui-même son événement.
 */
final class EventRules
{
    /** @var list<string> */
    public const PROHIBITED = [
        'id', 'uuid', 'slug', 'status', 'version', 'user_id', 'submitted_at', 'published_at',
        'rejected_at', 'rejection_reason', 'cancelled_at', 'cancelled_by', 'cancellation_reason',
        'registrations_count', 'cover_media_id', 'reminder_sent_at', 'created_at', 'updated_at',
    ];

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(bool $partial, User $organizer): array
    {
        // En PATCH, un champ absent reste inchangé ; un champ obligatoire présent ne peut pas être vide.
        $required = $partial ? ['sometimes', 'required'] : ['required'];
        $optional = $partial ? ['sometimes', 'nullable'] : ['nullable'];
        $text = [new NoHtml, new NoInvisibleCharacters, new SafeLinksInText];
        $url = [...$optional, 'string', 'max:2048', new SafeExternalUrl];

        $rules = [
            'title' => [...$required, 'string', 'min:5', 'max:150', ...$text],
            'description' => [...$required, 'string', 'min:30', 'max:10000', ...$text],
            'type' => [...$required, Rule::enum(EventType::class)],
            'format' => [...$required, Rule::enum(EventFormat::class)],
            'language' => [...$required, 'string', new LanguageCode],
            'categories' => [...$optional, 'array', 'max:'.(int) config('events.max_categories')],
            'categories.*' => ['required', 'string', 'distinct', 'max:100', new ActiveServiceCategory],
            // Un prestataire est présenté par son profil public : pas de nom d'organisateur.
            'organizer_name' => Organizers::isProvider($organizer)
                ? ['prohibited']
                : [...$optional, 'string', 'min:2', 'max:120', ...$text],
            'venue_name' => [...$optional, 'string', 'max:150', ...$text],
            'address_line' => [...$optional, 'string', 'max:255', ...$text],
            'postal_code' => [...$optional, 'string', 'max:20', 'regex:/^[A-Za-z0-9][A-Za-z0-9 \-]*$/'],
            'city' => [...$optional, 'string', 'max:100', ...$text],
            'country' => [...$optional, 'string', 'size:2', new CountryCode],
            'address_visibility' => ['sometimes', 'required', Rule::enum(AddressVisibility::class)],
            'online_url' => $url,
            'external_url' => $url,
            'ticket_url' => $url,
            'is_free' => ['sometimes', 'required', 'boolean'],
            'starts_at' => [...$required, 'date_format:'.EventContent::LOCAL_FORMAT],
            'ends_at' => [...$required, 'date_format:'.EventContent::LOCAL_FORMAT],
            'timezone' => [...$required, 'string', 'max:64', 'timezone:all'],
            'capacity' => [...$optional, 'integer', 'min:1', 'max:'.(int) config('events.max_registrations')],
            'cover' => [...$optional, 'string', 'uuid'],
        ];

        foreach (self::PROHIBITED as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    /**
     * Met le code pays en majuscules avant la validation.
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
