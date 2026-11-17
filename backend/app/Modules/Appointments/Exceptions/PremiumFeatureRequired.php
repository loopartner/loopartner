<?php

namespace App\Modules\Appointments\Exceptions;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Fonctionnalité ou quota Premium demandé sans Premium : 403 avec le code stable commun au Blog,
 * à Compliance et à Marketplace ("premium_required") et la fonctionnalité concernée :
 *
 *   {"message": "…", "code": "premium_required", "feature": "public_booking_page"}
 *
 * Fonctionnalités : public_booking_page, appointment_types, weekly_ranges,
 * availability_exceptions, booking_horizon, upcoming_appointments, reminder_offsets.
 */
class PremiumFeatureRequired extends HttpException
{
    public const CODE = 'premium_required';

    /**
     * @var array<string, string>
     */
    private const MESSAGES = [
        'public_booking_page' => 'La page de réservation publique est réservée au Premium. Les rendez-vous restent possibles gratuitement dans vos mises en relation acceptées.',
        'appointment_types' => 'Vous avez atteint le nombre de types de rendez-vous réservables de l\'offre gratuite. Le Premium en permet davantage.',
        'weekly_ranges' => 'Vous avez atteint le nombre de plages hebdomadaires de l\'offre gratuite. Le Premium en permet davantage.',
        'availability_exceptions' => 'Vous avez atteint le nombre d\'exceptions à venir de l\'offre gratuite. Le Premium en permet davantage.',
        'booking_horizon' => 'Cet horizon de réservation est réservé au Premium.',
        'upcoming_appointments' => 'Vous avez atteint le nombre de rendez-vous à venir de l\'offre gratuite. Le Premium en permet davantage.',
        'reminder_offsets' => 'Ces délais de rappel sont réservés au Premium.',
    ];

    public function __construct(public readonly string $feature)
    {
        parent::__construct(403, self::MESSAGES[$feature] ?? 'Cette fonctionnalité est réservée au Premium.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => self::CODE,
            'feature' => $this->feature,
        ], 403);
    }
}
