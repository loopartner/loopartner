<?php

namespace App\Support\Agenda;

use App\Models\User;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Agenda unifié d'un utilisateur : éléments de toutes les sources déclarées dans
 * config/agenda.php (sources), triés par début.
 */
final class Agenda
{
    /**
     * @return array<string, AgendaSource>
     */
    public static function sources(): array
    {
        $sources = [];

        foreach ((array) config('agenda.sources') as $key => $class) {
            $source = app((string) $class);

            if (! $source instanceof AgendaSource) {
                throw new LogicException("La source d'agenda {$key} n'implémente pas AgendaSource.");
            }

            $sources[(string) $key] = $source;
        }

        return $sources;
    }

    /**
     * @param  list<string>|null  $only  clés des sources à lire (toutes par défaut)
     * @return array{items: list<AgendaItem>, disclaimers: array<string, array{text: string, version: int}>}
     */
    public static function for(User $user, CarbonImmutable $from, CarbonImmutable $to, string $locale, bool $forFeed, ?array $only = null): array
    {
        $items = [];
        $disclaimers = [];

        foreach (self::sources() as $key => $source) {
            if ($only !== null && ! in_array($key, $only, true)) {
                continue;
            }

            $found = $source->items($user, $from, $to, $locale, $forFeed);
            $disclaimer = $found === [] ? null : $source->disclaimer($locale);

            if ($disclaimer !== null) {
                $disclaimers[$key] = $disclaimer;
            }

            array_push($items, ...$found);
        }

        usort($items, fn (AgendaItem $a, AgendaItem $b): int => [$a->startsAt->getTimestamp(), $a->source, $a->ref] <=> [$b->startsAt->getTimestamp(), $b->source, $b->ref]);

        return ['items' => $items, 'disclaimers' => $disclaimers];
    }
}
