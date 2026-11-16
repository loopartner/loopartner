<?php

namespace App\Modules\Events\Enums;

/**
 * Emails du module Events (EventNotification) : destinataire, textes en français et en anglais.
 *
 * Variables : :title (titre de l'événement, validé par un admin ou écrit par l'organisateur
 * destinataire), :date (début, à l'heure locale de l'événement). Aucun email ne contient le lien
 * de connexion, le texte d'une annonce, ni de nom ou d'email d'un autre utilisateur. Le motif
 * d'une décision (écrit par un admin) est ajouté à part, pour l'organisateur seulement.
 */
enum EventNotice: string
{
    // Participants (inscrits).
    case RegistrationConfirmed = 'registration_confirmed';
    case Reminder = 'reminder';
    case Changed = 'changed';
    case Cancelled = 'cancelled';
    case AnnouncementPublished = 'announcement_published';
    case RegistrationRemoved = 'registration_removed';

    // Organisateur.
    case EventApproved = 'event_approved';
    case EventRejected = 'event_rejected';
    case RevisionApproved = 'revision_approved';
    case RevisionRejected = 'revision_rejected';
    case AnnouncementApproved = 'announcement_approved';
    case AnnouncementRejected = 'announcement_rejected';
    case CancelledByModeration = 'cancelled_by_moderation';

    public function isForOrganizer(): bool
    {
        return in_array($this, [
            self::EventApproved, self::EventRejected, self::RevisionApproved, self::RevisionRejected,
            self::AnnouncementApproved, self::AnnouncementRejected, self::CancelledByModeration,
        ], true);
    }

    /**
     * Catégorie de notifications (config/notifications.php) : le participant peut couper les
     * rappels et les annonces ; la confirmation, les changements importants, l'annulation, le
     * retrait et les décisions de modération (organisateur) partent toujours.
     */
    public function notificationCategory(): string
    {
        return match (true) {
            $this->isForOrganizer() => 'events.organizer',
            $this === self::Reminder => 'events.reminders',
            $this === self::AnnouncementPublished => 'events.announcements',
            default => 'events.participation',
        };
    }

    /**
     * Email abandonné si le destinataire n'est plus inscrit au moment de l'envoi.
     */
    public function requiresActiveRegistration(): bool
    {
        return in_array($this, [self::Reminder, self::Changed, self::AnnouncementPublished], true);
    }

    /**
     * @return array{subject: string, intro: string, action: string, outro?: string}
     */
    public function texts(string $locale): array
    {
        return self::TEXTS[$this->value][$locale] ?? self::TEXTS[$this->value]['fr'];
    }

    /**
     * @var array<string, array<string, array{subject: string, intro: string, action: string, outro?: string}>>
     */
    private const TEXTS = [
        'registration_confirmed' => [
            'fr' => ['subject' => 'Inscription confirmée : :title', 'intro' => 'Votre inscription à « :title » est confirmée. L\'événement commence le :date.', 'action' => 'Voir l\'événement', 'outro' => 'Les informations pratiques (lieu ou lien de connexion) sont sur la page de l\'événement, une fois connecté.'],
            'en' => ['subject' => 'Registration confirmed: :title', 'intro' => 'Your registration for ":title" is confirmed. The event starts on :date.', 'action' => 'View the event', 'outro' => 'Practical details (venue or connection link) are on the event page once you are logged in.'],
        ],
        'reminder' => [
            'fr' => ['subject' => 'Rappel : :title commence bientôt', 'intro' => '« :title » commence le :date.', 'action' => 'Voir l\'événement', 'outro' => 'Les informations pratiques (lieu ou lien de connexion) sont sur la page de l\'événement, une fois connecté.'],
            'en' => ['subject' => 'Reminder: :title starts soon', 'intro' => '":title" starts on :date.', 'action' => 'View the event', 'outro' => 'Practical details (venue or connection link) are on the event page once you are logged in.'],
        ],
        'changed' => [
            'fr' => ['subject' => 'Modification de l\'événement :title', 'intro' => 'L\'organisateur de « :title » a modifié des informations importantes (date, lieu ou lien de connexion). L\'événement commence le :date.', 'action' => 'Voir les nouvelles informations'],
            'en' => ['subject' => 'Update to the event :title', 'intro' => 'The organiser of ":title" has changed important information (date, venue or connection link). The event starts on :date.', 'action' => 'View the new information'],
        ],
        'cancelled' => [
            'fr' => ['subject' => 'Événement annulé : :title', 'intro' => 'L\'événement « :title », prévu le :date, a été annulé.', 'action' => 'Voir l\'événement'],
            'en' => ['subject' => 'Event cancelled: :title', 'intro' => 'The event ":title", scheduled for :date, has been cancelled.', 'action' => 'View the event'],
        ],
        'announcement_published' => [
            'fr' => ['subject' => 'Nouvelle annonce : :title', 'intro' => 'L\'organisateur de « :title » a publié une annonce pour les inscrits. Connectez-vous pour la lire.', 'action' => 'Lire l\'annonce'],
            'en' => ['subject' => 'New announcement: :title', 'intro' => 'The organiser of ":title" has posted an announcement for attendees. Log in to read it.', 'action' => 'Read the announcement'],
        ],
        'registration_removed' => [
            'fr' => ['subject' => 'Inscription annulée : :title', 'intro' => 'Votre inscription à « :title » a été annulée par l\'organisateur.', 'action' => 'Voir l\'agenda'],
            'en' => ['subject' => 'Registration cancelled: :title', 'intro' => 'Your registration for ":title" has been cancelled by the organiser.', 'action' => 'View the agenda'],
        ],
        'event_approved' => [
            'fr' => ['subject' => 'Votre événement est publié', 'intro' => '« :title » a été validé par l\'équipe Loopartner et figure maintenant dans l\'agenda.', 'action' => 'Voir mon événement'],
            'en' => ['subject' => 'Your event is published', 'intro' => '":title" has been approved by the Loopartner team and is now in the agenda.', 'action' => 'View my event'],
        ],
        'event_rejected' => [
            'fr' => ['subject' => 'Votre événement n\'a pas été validé', 'intro' => '« :title » n\'a pas été validé par l\'équipe Loopartner.', 'action' => 'Corriger mon événement', 'outro' => 'Vous pouvez le corriger puis le soumettre à nouveau.'],
            'en' => ['subject' => 'Your event was not approved', 'intro' => '":title" was not approved by the Loopartner team.', 'action' => 'Edit my event', 'outro' => 'You can edit it and submit it again.'],
        ],
        'revision_approved' => [
            'fr' => ['subject' => 'Votre modification est publiée', 'intro' => 'Votre modification de « :title » a été validée par l\'équipe Loopartner et est maintenant visible.', 'action' => 'Voir mon événement'],
            'en' => ['subject' => 'Your changes are published', 'intro' => 'Your changes to ":title" have been approved by the Loopartner team and are now visible.', 'action' => 'View my event'],
        ],
        'revision_rejected' => [
            'fr' => ['subject' => 'Votre modification n\'a pas été validée', 'intro' => 'Votre modification de « :title » n\'a pas été validée par l\'équipe Loopartner. La version validée précédemment reste affichée.', 'action' => 'Voir mon événement'],
            'en' => ['subject' => 'Your changes were not approved', 'intro' => 'Your changes to ":title" were not approved by the Loopartner team. The previously approved version remains visible.', 'action' => 'View my event'],
        ],
        'announcement_approved' => [
            'fr' => ['subject' => 'Votre annonce a été envoyée', 'intro' => 'Votre annonce aux inscrits de « :title » a été validée et envoyée.', 'action' => 'Voir mon événement'],
            'en' => ['subject' => 'Your announcement has been sent', 'intro' => 'Your announcement to the attendees of ":title" has been approved and sent.', 'action' => 'View my event'],
        ],
        'announcement_rejected' => [
            'fr' => ['subject' => 'Votre annonce n\'a pas été validée', 'intro' => 'Votre annonce aux inscrits de « :title » n\'a pas été validée par l\'équipe Loopartner.', 'action' => 'Voir mon événement'],
            'en' => ['subject' => 'Your announcement was not approved', 'intro' => 'Your announcement to the attendees of ":title" was not approved by the Loopartner team.', 'action' => 'View my event'],
        ],
        'cancelled_by_moderation' => [
            'fr' => ['subject' => 'Votre événement a été annulé', 'intro' => '« :title » a été annulé par l\'équipe Loopartner. Les inscrits ont été prévenus.', 'action' => 'Voir mon événement'],
            'en' => ['subject' => 'Your event has been cancelled', 'intro' => '":title" has been cancelled by the Loopartner team. Attendees have been notified.', 'action' => 'View my event'],
        ],
    ];
}
