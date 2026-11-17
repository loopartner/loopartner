<?php

namespace App\Modules\Appointments\Enums;

/**
 * Emails des rendez-vous (AppointmentNotification, décision 56), en français et en anglais.
 *
 * Variables : :type (titre du type de rendez-vous) et :date (date et heure dans le fuseau du
 * destinataire). Jamais de note, d'adresse, de téléphone, de lien de visio ni de nom : le
 * destinataire lit le détail dans son espace.
 */
enum AppointmentNotice: string
{
    case RequestReceived = 'request_received';
    case ProposalReceived = 'proposal_received';
    case Confirmed = 'confirmed';
    case Declined = 'declined';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case ChangeProposed = 'change_proposed';
    case ChangeAccepted = 'change_accepted';
    case ChangeDeclined = 'change_declined';
    case ChangeExpired = 'change_expired';
    case Reminder = 'reminder';

    /**
     * Les rappels peuvent être désactivés ; tout le reste concerne un engagement et part
     * toujours.
     */
    public function category(): string
    {
        return $this === self::Reminder ? 'appointments.reminders' : 'appointments.bookings';
    }

    /**
     * Un .ics (titre et dates seulement) est joint à la confirmation et au report accepté.
     */
    public function attachesIcs(): bool
    {
        return $this === self::Confirmed || $this === self::ChangeAccepted;
    }

    /**
     * @return array{subject: string, intro: string, action: string}
     */
    public function texts(string $locale): array
    {
        return self::TEXTS[$this->value][$locale] ?? self::TEXTS[$this->value]['fr'];
    }

    /**
     * @var array<string, array<string, array{subject: string, intro: string, action: string}>>
     */
    private const TEXTS = [
        'request_received' => [
            'fr' => ['subject' => 'Nouvelle demande de rendez-vous', 'intro' => 'Un client demande un rendez-vous « :type » le :date. Confirmez-le ou refusez-le depuis votre espace : sans réponse, la demande expirera.', 'action' => 'Voir la demande'],
            'en' => ['subject' => 'New appointment request', 'intro' => 'A client is requesting a ":type" appointment on :date. Confirm or decline it from your account: without a reply, the request will expire.', 'action' => 'View the request'],
        ],
        'proposal_received' => [
            'fr' => ['subject' => 'Un prestataire vous propose un rendez-vous', 'intro' => 'Un prestataire vous propose un rendez-vous « :type » le :date. Confirmez-le ou refusez-le depuis votre espace.', 'action' => 'Voir la proposition'],
            'en' => ['subject' => 'A provider is offering you an appointment', 'intro' => 'A provider is offering you a ":type" appointment on :date. Confirm or decline it from your account.', 'action' => 'View the offer'],
        ],
        'confirmed' => [
            'fr' => ['subject' => 'Rendez-vous confirmé', 'intro' => 'Votre rendez-vous « :type » du :date est confirmé. Le fichier joint l\'ajoute à votre agenda ; le lieu ou le lien se trouve dans votre espace.', 'action' => 'Voir le rendez-vous'],
            'en' => ['subject' => 'Appointment confirmed', 'intro' => 'Your ":type" appointment on :date is confirmed. The attached file adds it to your calendar; the place or link is in your account.', 'action' => 'View the appointment'],
        ],
        'declined' => [
            'fr' => ['subject' => 'Rendez-vous refusé', 'intro' => 'Votre demande de rendez-vous « :type » du :date a été refusée. Vous pouvez proposer un autre créneau depuis votre espace.', 'action' => 'Voir le rendez-vous'],
            'en' => ['subject' => 'Appointment declined', 'intro' => 'Your ":type" appointment request for :date was declined. You can suggest another time from your account.', 'action' => 'View the appointment'],
        ],
        'cancelled' => [
            'fr' => ['subject' => 'Rendez-vous annulé', 'intro' => 'Le rendez-vous « :type » du :date a été annulé. Le créneau est libéré.', 'action' => 'Voir le rendez-vous'],
            'en' => ['subject' => 'Appointment cancelled', 'intro' => 'The ":type" appointment on :date has been cancelled. The time slot is free again.', 'action' => 'View the appointment'],
        ],
        'expired' => [
            'fr' => ['subject' => 'Demande de rendez-vous expirée', 'intro' => 'Votre demande de rendez-vous « :type » du :date n\'a pas reçu de réponse à temps : elle a expiré et le créneau est libéré.', 'action' => 'Voir le rendez-vous'],
            'en' => ['subject' => 'Appointment request expired', 'intro' => 'Your ":type" appointment request for :date did not get a reply in time: it has expired and the time slot is free again.', 'action' => 'View the appointment'],
        ],
        'change_proposed' => [
            'fr' => ['subject' => 'Report de rendez-vous proposé', 'intro' => 'Un nouvel horaire est proposé pour votre rendez-vous « :type » : le :date. Acceptez-le ou refusez-le depuis votre espace ; sinon, l\'horaire actuel reste valable.', 'action' => 'Voir la proposition'],
            'en' => ['subject' => 'Appointment change proposed', 'intro' => 'A new time is proposed for your ":type" appointment: :date. Accept or decline it from your account; otherwise, the current time stays.', 'action' => 'View the proposal'],
        ],
        'change_accepted' => [
            'fr' => ['subject' => 'Rendez-vous déplacé', 'intro' => 'Votre rendez-vous « :type » est déplacé au :date. Le fichier joint met à jour votre agenda.', 'action' => 'Voir le rendez-vous'],
            'en' => ['subject' => 'Appointment moved', 'intro' => 'Your ":type" appointment has been moved to :date. The attached file updates your calendar.', 'action' => 'View the appointment'],
        ],
        'change_declined' => [
            'fr' => ['subject' => 'Report de rendez-vous refusé', 'intro' => 'Le nouvel horaire proposé pour le rendez-vous « :type » a été refusé : le rendez-vous reste prévu le :date.', 'action' => 'Voir le rendez-vous'],
            'en' => ['subject' => 'Appointment change declined', 'intro' => 'The new time proposed for the ":type" appointment was declined: the appointment remains scheduled for :date.', 'action' => 'View the appointment'],
        ],
        'change_expired' => [
            'fr' => ['subject' => 'Report de rendez-vous sans réponse', 'intro' => 'Le nouvel horaire proposé pour le rendez-vous « :type » n\'a pas reçu de réponse à temps : le rendez-vous reste prévu le :date.', 'action' => 'Voir le rendez-vous'],
            'en' => ['subject' => 'Appointment change not answered', 'intro' => 'The new time proposed for the ":type" appointment did not get a reply in time: the appointment remains scheduled for :date.', 'action' => 'View the appointment'],
        ],
        'reminder' => [
            'fr' => ['subject' => 'Rappel : rendez-vous à venir', 'intro' => 'Rappel : votre rendez-vous « :type » a lieu le :date. Le lieu ou le lien se trouve dans votre espace.', 'action' => 'Voir le rendez-vous'],
            'en' => ['subject' => 'Reminder: upcoming appointment', 'intro' => 'Reminder: your ":type" appointment takes place on :date. The place or link is in your account.', 'action' => 'View the appointment'],
        ],
    ];
}
