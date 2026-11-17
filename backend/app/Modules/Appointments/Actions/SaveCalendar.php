<?php

namespace App\Modules\Appointments\Actions;

use App\Models\User;
use App\Modules\Accounts\Actions\HasPublishedProviderProfile;
use App\Modules\Appointments\Models\Calendar;
use App\Modules\Appointments\Support\Calendars;
use App\Modules\Appointments\Support\Plan;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Réglages du calendrier du prestataire connecté (PUT, représentation complète).
 *
 * - Ouvrir les réservations exige un profil prestataire publié avec une catégorie (403) : un
 *   profil masqué par la modération compte comme non publié.
 * - Page publique : Premium (403 premium_required) ; horizon et délais de rappel bornés par le
 *   plan (403 premium_required au-delà de l'offre gratuite).
 * - Les rendez-vous déjà pris ne changent pas.
 *
 * @phpstan-type CalendarInput array{timezone: string, is_enabled: bool, public_page_enabled?: bool, slot_step_minutes: int, buffer_minutes: int, min_notice_hours: int, horizon_days: int, reminder_offsets: list<int>}
 */
class SaveCalendar
{
    public function __construct(
        private Plan $plan,
        private HasPublishedProviderProfile $hasPublishedProfile,
    ) {}

    /**
     * @param  CalendarInput  $data
     */
    public function handle(User $provider, array $data): Calendar
    {
        $publicPage = (bool) ($data['public_page_enabled'] ?? false);

        if ($data['is_enabled'] && ! $this->hasPublishedProfile->handle($provider)) {
            throw new AccessDeniedHttpException('Publiez votre profil prestataire, avec au moins une catégorie, avant d\'ouvrir les réservations.');
        }

        if ($publicPage) {
            $this->plan->assertAllows($provider, 'public_booking_page', 'public_booking_page');
        }

        $this->plan->assertAtMost($provider, 'horizon_days', $data['horizon_days'], 'booking_horizon', 'L\'horizon de réservation est limité à %d jours.');
        $this->assertReminderOffsets($provider, array_values(array_unique($data['reminder_offsets'])));

        $calendar = Calendars::of($provider);

        return DB::transaction(function () use ($calendar, $data, $publicPage, $provider): Calendar {
            $calendar = Calendars::lock($calendar->id);
            $pageWasEnabled = $calendar->public_page_enabled;

            $calendar->fill([
                'timezone' => $data['timezone'],
                'is_enabled' => $data['is_enabled'],
                'public_page_enabled' => $publicPage,
                'slot_step_minutes' => $data['slot_step_minutes'],
                'buffer_minutes' => $data['buffer_minutes'],
                'min_notice_minutes' => $data['min_notice_hours'] * 60,
                'horizon_days' => $data['horizon_days'],
                'reminder_offsets' => array_values(array_unique($data['reminder_offsets'])),
            ])->save();

            if ($pageWasEnabled !== $publicPage) {
                SecurityLog::record($publicPage ? 'appointments.public_page_enabled' : 'appointments.public_page_disabled', ['user_id' => $provider->id]);
            }

            return $calendar;
        });
    }

    /**
     * @param  list<int>  $offsets
     */
    private function assertReminderOffsets(User $provider, array $offsets): void
    {
        $set = $this->plan->of($provider);
        $allowed = $set->choices('appointments.reminder_offsets');
        $max = $set->limit('appointments.max_reminder_offsets');

        if (array_diff($offsets, $allowed) !== [] || ($max !== null && count($offsets) > $max)) {
            $this->plan->requirePremium($provider, 'reminder_offsets');
        }
    }
}
