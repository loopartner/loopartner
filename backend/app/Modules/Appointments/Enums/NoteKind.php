<?php

namespace App\Modules\Appointments\Enums;

/**
 * Texte libre d'un rendez-vous : motif ou note de la réservation, message d'annulation, de
 * refus ou de report.
 */
enum NoteKind: string
{
    case Booking = 'booking';
    case Cancellation = 'cancellation';
    case Decline = 'decline';
    case Reschedule = 'reschedule';
}
