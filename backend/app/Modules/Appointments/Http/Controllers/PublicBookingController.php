<?php

namespace App\Modules\Appointments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Accounts\Actions\GetProviderSummaries;
use App\Modules\Appointments\Actions\BookFromPublicPage;
use App\Modules\Appointments\Http\Requests\AppointmentInputRequest;
use App\Modules\Appointments\Models\AppointmentType;
use App\Modules\Appointments\Support\AppointmentPresenter;
use App\Modules\Appointments\Support\BookableTypes;
use App\Modules\Appointments\Support\BookingNotices;
use App\Modules\Appointments\Support\Plan;
use App\Modules\Appointments\Support\SlotGenerator;
use App\Modules\Appointments\Support\SlotPresenter;
use App\Modules\Appointments\Support\SlotRange;
use App\Support\Locale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Page de réservation publique d'un prestataire Premium (décision 52).
 *
 * - GET /providers/{slug}/booking (public, limité par IP) : profil public, types publics et,
 *   avec ?type=, heures de début libres (sans distinguer un créneau réservé d'un créneau
 *   fermé), et les textes d'information à afficher avant la réservation. 404 si la page est
 *   fermée.
 * - POST /providers/{slug}/appointments : réservation avec un motif (BookFromPublicPage).
 */
class PublicBookingController extends Controller
{
    public function show(Request $request, string $slug, BookFromPublicPage $page, BookableTypes $bookableTypes, SlotGenerator $slots, Plan $plan, GetProviderSummaries $summaries): JsonResponse
    {
        $request->validate([
            'type' => ['sometimes', 'string', 'uuid'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $calendar = $page->openPage($slug);
        $types = $bookableTypes->of($calendar, publicOnly: true);
        $data = [
            'provider' => $summaries->handle([$calendar->user_id])[$calendar->user_id] ?? null,
            'types' => $types->map(fn (AppointmentType $type): array => AppointmentPresenter::publicType($type))->values()->all(),
            'slots' => [],
        ];
        $meta = [
            'timezone' => $calendar->timezone,
            'notices' => BookingNotices::for(['public_booking_privacy', 'note_warning'], Locale::fromRequest($request)),
        ];

        if ($request->filled('type')) {
            $type = $types->firstWhere('uuid', $request->string('type')->toString());

            if ($type === null) {
                throw ValidationException::withMessages(['type' => 'Choisissez un type de rendez-vous proposé par ce prestataire.']);
            }

            $range = SlotRange::fromRequest($request, $calendar);
            $data['slots'] = SlotPresenter::many($slots->between($calendar, $type->duration_minutes, $range['from'], $range['to'], $plan->horizonDays($calendar)), $calendar->timezone);
            $meta += ['from' => $range['from_date'], 'to' => $range['to_date']];
        }

        return response()->json(['data' => $data, 'meta' => $meta]);
    }

    public function store(AppointmentInputRequest $request, string $slug, BookFromPublicPage $bookFromPublicPage, AppointmentPresenter $presenter): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{type: string, starts_at: string, note?: string|null, address?: string|null, phone?: string|null} $data */
        $data = $request->validated();
        $appointment = $bookFromPublicPage->handle($user, $slug, $data);

        return response()->json(['data' => $presenter->present($appointment, $user, Locale::fromRequest($request))], 201);
    }
}
