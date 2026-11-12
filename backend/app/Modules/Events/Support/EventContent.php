<?php

namespace App\Modules\Events\Support;

use App\Models\ServiceCategory;
use App\Modules\Events\Enums\AddressVisibility;
use App\Modules\Events\Enums\EventFormat;
use App\Modules\Events\Models\Event;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

/**
 * Contenu d'un événement sous forme de tableau : tous les champs visibles, les catégories
 * (slugs triés) et les dates en UTC (ISO 8601, "Z").
 *
 * Il sert à fusionner une modification partielle (PATCH), à vérifier la cohérence et la
 * complétude d'un événement, à stocker la version proposée d'une révision, à l'appliquer après
 * validation, et à lister les champs modifiés.
 *
 * Dates saisies : heure locale de l'événement ("2026-12-03T18:30", sans décalage) et fuseau
 * horaire IANA. La conversion en UTC se fait ici, une seule fois. Une heure qui n'existe pas
 * (passage à l'heure d'été) est refusée.
 *
 * @phpstan-type Content array<string, mixed>
 */
final class EventContent
{
    /**
     * Champs de contenu, tous soumis à la validation d'un admin pour un événement publié.
     *
     * @var list<string>
     */
    public const FIELDS = [
        'type', 'format', 'title', 'description', 'organizer_name', 'language', 'venue_name',
        'address_line', 'postal_code', 'city', 'country', 'address_visibility', 'online_url',
        'external_url', 'is_free', 'ticket_url', 'starts_at', 'ends_at', 'timezone', 'capacity',
    ];

    /**
     * Modifications importantes pour les inscrits (date, lieu, lien de connexion) : elles leur
     * sont annoncées par email après validation.
     *
     * @var list<string>
     */
    public const IMPORTANT_FIELDS = [
        'starts_at', 'ends_at', 'timezone', 'format', 'venue_name', 'address_line', 'postal_code',
        'city', 'country', 'online_url',
    ];

    /** @var list<string> */
    public const VENUE_FIELDS = ['venue_name', 'address_line', 'postal_code', 'city', 'country'];

    /**
     * Format des dates saisies (heure locale de l'événement).
     */
    public const LOCAL_FORMAT = 'Y-m-d\TH:i';

    private const STORED_FORMAT = 'Y-m-d\TH:i:s\Z';

    /**
     * Contenu actuel d'un événement.
     *
     * @return Content
     */
    public static function of(Event $event): array
    {
        $content = [];

        foreach (self::FIELDS as $field) {
            $value = $event->getAttribute($field);

            $content[$field] = match (true) {
                $value instanceof BackedEnum => $value->value,
                $value instanceof DateTimeInterface => self::stored($value),
                default => $value,
            };
        }

        $content['categories'] = $event->relationLoaded('categories')
            ? $event->categories->pluck('slug')->sort()->values()->all()
            : $event->categories()->orderBy('slug')->pluck('slug')->all();

        return $content;
    }

    /**
     * Applique des données validées (champs présents uniquement) sur un contenu.
     *
     * @param  Content  $base
     * @param  array<string, mixed>  $input
     * @return Content
     *
     * @throws ValidationException heure locale inexistante dans le fuseau
     */
    public static function merge(array $base, array $input): array
    {
        $content = $base;

        foreach (self::FIELDS as $field) {
            if (! in_array($field, ['starts_at', 'ends_at', 'timezone'], true) && array_key_exists($field, $input)) {
                $content[$field] = $input[$field];
            }
        }

        if (array_key_exists('is_free', $input)) {
            $content['is_free'] = filter_var($input['is_free'], FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('capacity', $input)) {
            $content['capacity'] = is_numeric($input['capacity']) ? (int) $input['capacity'] : null;
        }

        if (array_key_exists('categories', $input)) {
            /** @var list<string> $categories */
            $categories = array_values(array_unique((array) ($input['categories'] ?? [])));
            sort($categories);
            $content['categories'] = $categories;
        }

        // Un changement de fuseau seul conserve l'heure locale saisie précédemment.
        $previousTimezone = isset($base['timezone']) && is_string($base['timezone']) ? $base['timezone'] : null;
        $timezone = isset($input['timezone']) && is_string($input['timezone']) ? $input['timezone'] : $previousTimezone;
        $content['timezone'] = $timezone;

        foreach (['starts_at', 'ends_at'] as $field) {
            $local = isset($input[$field]) && is_string($input[$field])
                ? $input[$field]
                : (isset($base[$field], $previousTimezone) && is_string($base[$field]) ? self::localTime($base[$field], $previousTimezone) : null);

            $content[$field] = $local !== null && $timezone !== null ? self::toUtc($local, $timezone, $field) : null;
        }

        return self::normalize($content);
    }

    /**
     * Retire les champs incompatibles avec le format et la gratuité : un événement en ligne n'a
     * pas de lieu, un événement en présentiel pas de lien de connexion, un événement gratuit pas
     * de billetterie, un événement payant pas de capacité (inscriptions externes).
     *
     * @param  Content  $content
     * @return Content
     */
    public static function normalize(array $content): array
    {
        $format = EventFormat::tryFrom((string) ($content['format'] ?? ''));

        if ($format === EventFormat::Online) {
            foreach (self::VENUE_FIELDS as $field) {
                $content[$field] = null;
            }
            $content['address_visibility'] = AddressVisibility::Public->value;
        }

        if ($format === EventFormat::InPerson) {
            $content['online_url'] = null;
        }

        if (($content['is_free'] ?? true) === true) {
            $content['ticket_url'] = null;
        } else {
            $content['capacity'] = null;
        }

        return $content;
    }

    /**
     * Cohérence, vérifiée à chaque enregistrement (brouillon compris) : la fin après le début,
     * une durée et une échéance raisonnables, une capacité compatible avec les inscrits.
     *
     * @param  Content  $content
     *
     * @throws ValidationException
     */
    public static function assertConsistent(array $content, int $registrations = 0): void
    {
        $errors = self::consistencyErrors($content, $registrations);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Complétude, vérifiée avant la validation par un admin (soumission, révision, publication
     * directe) : les champs exigés par le format et la gratuité, un nom d'organisateur (sauf
     * pour un prestataire, présenté par son profil) et un début suffisamment éloigné.
     *
     * @param  Content  $content
     *
     * @throws ValidationException
     */
    public static function assertComplete(array $content, bool $organizerIsProvider, int $registrations = 0): void
    {
        $errors = self::consistencyErrors($content, $registrations);
        $format = EventFormat::tryFrom((string) ($content['format'] ?? ''));

        if ($format?->hasVenue() === true) {
            foreach (['venue_name' => 'le nom du lieu', 'address_line' => 'l\'adresse', 'city' => 'la ville', 'country' => 'le pays'] as $field => $label) {
                if (blank($content[$field] ?? null)) {
                    $errors[$field] ??= "Indiquez {$label} d'un événement en présentiel ou hybride.";
                }
            }
        }

        if ($format?->isOnline() === true && blank($content['online_url'] ?? null)) {
            $errors['online_url'] ??= 'Indiquez le lien de connexion d\'un événement en ligne ou hybride.';
        }

        if (($content['is_free'] ?? true) === false && blank($content['ticket_url'] ?? null)) {
            $errors['ticket_url'] ??= 'Indiquez le lien de la billetterie d\'un événement payant.';
        }

        if (! $organizerIsProvider && blank($content['organizer_name'] ?? null)) {
            $errors['organizer_name'] ??= 'Indiquez le nom de l\'organisateur affiché sur l\'événement.';
        }

        $start = self::date($content['starts_at'] ?? null);
        $lead = (int) config('events.min_lead_minutes');

        if ($start !== null && $start->lessThanOrEqualTo(now()->addMinutes($lead))) {
            $errors['starts_at'] ??= "L'événement doit commencer au moins {$lead} minutes après sa soumission.";
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Applique un contenu à un événement (sans l'enregistrer). Les catégories sont à
     * synchroniser par l'appelant (categoryIds()).
     *
     * @param  Content  $content
     */
    public static function apply(Event $event, array $content): void
    {
        $attributes = [];

        foreach (self::FIELDS as $field) {
            $attributes[$field] = $content[$field] ?? null;
        }

        $attributes['starts_at'] = self::date($attributes['starts_at']);
        $attributes['ends_at'] = self::date($attributes['ends_at']);
        $attributes['is_free'] = (bool) ($attributes['is_free'] ?? true);
        $attributes['address_visibility'] ??= AddressVisibility::Public->value;

        $event->fill($attributes);
    }

    /**
     * Identifiants internes des catégories du contenu.
     *
     * @param  Content  $content
     * @return list<int>
     */
    public static function categoryIds(array $content): array
    {
        /** @var list<string> $slugs */
        $slugs = (array) ($content['categories'] ?? []);

        /** @var list<int> */
        return ServiceCategory::query()->whereIn('slug', $slugs)->pluck('id')->all();
    }

    /**
     * Noms des champs dont la valeur diffère (catégories comprises), dans l'ordre de FIELDS.
     *
     * @param  Content  $before
     * @param  Content  $after
     * @return list<string>
     */
    public static function changedFields(array $before, array $after): array
    {
        $changed = [];

        foreach ([...self::FIELDS, 'categories'] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /**
     * Heure locale ("2026-12-03T18:30") d'une date UTC stockée, dans le fuseau donné.
     */
    public static function localTime(string|DateTimeInterface $utc, string $timezone): string
    {
        return CarbonImmutable::parse($utc)->setTimezone($timezone)->format(self::LOCAL_FORMAT);
    }

    /**
     * Date UTC (ISO 8601 "Z") correspondant à une heure locale saisie.
     *
     * @throws ValidationException heure inexistante dans ce fuseau (changement d'heure)
     */
    public static function toUtc(string $local, string $timezone, string $field): string
    {
        $date = CarbonImmutable::createFromFormat('!'.self::LOCAL_FORMAT, $local, $timezone);

        if (! $date instanceof CarbonImmutable || $date->format(self::LOCAL_FORMAT) !== $local) {
            throw ValidationException::withMessages([
                $field => 'Cette heure n\'existe pas dans le fuseau horaire choisi (changement d\'heure).',
            ]);
        }

        return self::stored($date);
    }

    /**
     * @param  Content  $content
     * @return array<string, string>
     */
    private static function consistencyErrors(array $content, int $registrations): array
    {
        $errors = [];
        $start = self::date($content['starts_at'] ?? null);
        $end = self::date($content['ends_at'] ?? null);

        if ($start !== null && $end !== null) {
            $maxDays = (int) config('events.max_duration_days');
            $maxMonths = (int) config('events.max_months_ahead');

            if ($end->lessThanOrEqualTo($start)) {
                $errors['ends_at'] = 'La fin doit être postérieure au début.';
            } elseif ($end->greaterThan($start->addDays($maxDays))) {
                $errors['ends_at'] = "Un événement dure au plus {$maxDays} jours.";
            }

            if ($start->greaterThan(now()->addMonths($maxMonths))) {
                $errors['starts_at'] = "L'événement doit commencer dans les {$maxMonths} prochains mois.";
            }
        }

        $capacity = $content['capacity'] ?? null;

        if (is_int($capacity) && $capacity < $registrations) {
            $errors['capacity'] = "La capacité ne peut pas être inférieure au nombre d'inscrits ({$registrations}).";
        }

        return $errors;
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        return is_string($value) || $value instanceof DateTimeInterface ? CarbonImmutable::parse($value)->utc() : null;
    }

    private static function stored(DateTimeInterface $date): string
    {
        return CarbonImmutable::parse($date)->utc()->format(self::STORED_FORMAT);
    }
}
