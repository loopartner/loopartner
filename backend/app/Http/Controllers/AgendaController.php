<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Agenda\Agenda;
use App\Support\Agenda\AgendaItem;
use App\Support\Locale;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * GET /me/agenda : agenda unifié de l'utilisateur connecté (rendez-vous, événements suivis ou
 * organisés, échéances du suivi administratif), de from à to inclus (journées UTC, 30 jours
 * par défaut, 92 au plus), filtrable par source. Chaque élément ne concerne que l'utilisateur
 * connecté. disclaimers : avertissement de chaque source présente qui en a un (suivi
 * administratif).
 */
class AgendaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $keys = array_keys(Agenda::sources());
        $validated = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'sources' => ['sometimes', 'array', 'max:'.count($keys)],
            'sources.*' => ['string', Rule::in($keys)],
        ]);

        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from'], 'UTC') : CarbonImmutable::now()->utc()->startOfDay();
        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to'], 'UTC') : $from->addDays((int) config('agenda.default_range_days'));
        $maxDays = (int) config('agenda.max_range_days');

        if ($to->lessThan($from) || $from->diffInDays($to) > $maxDays - 1) {
            throw ValidationException::withMessages(['to' => "Demandez au plus {$maxDays} jours d'agenda à la fois."]);
        }

        /** @var User $user */
        $user = $request->user();
        $agenda = Agenda::for($user, $from, $to->addDay(), Locale::fromRequest($request), false, $validated['sources'] ?? null);

        return response()->json([
            'data' => array_map(fn (AgendaItem $item): array => $item->toArray(), $agenda['items']),
            'meta' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'sources' => $validated['sources'] ?? $keys],
            'disclaimers' => (object) $agenda['disclaimers'],
        ]);
    }
}
