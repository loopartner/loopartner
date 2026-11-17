<?php

namespace App\Modules\Marketplace\Enums;

/**
 * Emails du module Marketplace sur les devis (QuoteNotification, décision 46), en français et
 * en anglais.
 *
 * Variable : :number (numéro du devis). Jamais de montant, de ligne, de nom d'utilisateur ni de
 * titre de projet : le destinataire lit le devis dans son espace (et dans le PDF joint à la
 * confirmation d'acceptation).
 */
enum QuoteNotice: string
{
    case Received = 'received';
    case Declined = 'declined';
    case AcceptedForProvider = 'accepted_provider';
    case AcceptedForClient = 'accepted_client';
    case WithdrawalForProvider = 'withdrawal_provider';
    case WithdrawalForClient = 'withdrawal_client';

    /**
     * Catégorie de notification : l'acceptation (le contrat) et la rétractation partent
     * toujours ; le reste peut être désactivé.
     */
    public function category(): string
    {
        return in_array($this, [self::Received, self::Declined], true) ? 'marketplace.quotes' : 'marketplace.quote_decisions';
    }

    /**
     * Le PDF du devis accepté est joint (support durable, décision 46).
     */
    public function attachesPdf(): bool
    {
        return in_array($this, [self::AcceptedForProvider, self::AcceptedForClient], true);
    }

    /**
     * Rappel que Loopartner n'est pas partie au contrat.
     */
    public function mentionsContract(): bool
    {
        return $this !== self::Received && $this !== self::Declined;
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
        'received' => [
            'fr' => [
                'subject' => 'Vous avez reçu un devis',
                'intro' => 'Un prestataire vous a adressé un devis (n° :number) pour votre projet. Prenez le temps de le lire : rien n\'est engagé tant que vous ne l\'avez pas accepté.',
                'action' => 'Lire le devis',
            ],
            'en' => [
                'subject' => 'You have received a quote',
                'intro' => 'A provider has sent you a quote (no. :number) for your project. Take the time to read it: nothing is binding until you accept it.',
                'action' => 'Read the quote',
            ],
        ],
        'declined' => [
            'fr' => [
                'subject' => 'Votre devis a été refusé',
                'intro' => 'Votre devis n° :number a été refusé par le client. Vous pouvez en proposer une nouvelle version depuis votre espace.',
                'action' => 'Voir le devis',
            ],
            'en' => [
                'subject' => 'Your quote was declined',
                'intro' => 'Your quote no. :number was declined by the client. You can offer a new version from your account.',
                'action' => 'View the quote',
            ],
        ],
        'accepted_provider' => [
            'fr' => [
                'subject' => 'Votre devis a été accepté',
                'intro' => 'Votre devis n° :number a été accepté par le client. Le devis accepté est joint à cet email au format PDF.',
                'action' => 'Voir le devis',
            ],
            'en' => [
                'subject' => 'Your quote was accepted',
                'intro' => 'Your quote no. :number was accepted by the client. The accepted quote is attached to this email as a PDF.',
                'action' => 'View the quote',
            ],
        ],
        'accepted_client' => [
            'fr' => [
                'subject' => 'Confirmation : vous avez accepté un devis',
                'intro' => 'Vous avez accepté le devis n° :number. Il est joint à cet email au format PDF : conservez-le, il contient les engagements du prestataire.',
                'action' => 'Voir le devis',
            ],
            'en' => [
                'subject' => 'Confirmation: you have accepted a quote',
                'intro' => 'You have accepted quote no. :number. It is attached to this email as a PDF: keep it, it sets out the provider\'s commitments.',
                'action' => 'View the quote',
            ],
        ],
        'withdrawal_provider' => [
            'fr' => [
                'subject' => 'Rétractation du client',
                'intro' => 'Le client a exercé son droit de rétractation pour votre devis n° :number.',
                'action' => 'Voir le devis',
            ],
            'en' => [
                'subject' => 'Client withdrawal',
                'intro' => 'The client has exercised their right of withdrawal for your quote no. :number.',
                'action' => 'View the quote',
            ],
        ],
        'withdrawal_client' => [
            'fr' => [
                'subject' => 'Confirmation de votre rétractation',
                'intro' => 'Nous avons bien enregistré votre rétractation pour le devis n° :number, et le prestataire en est informé.',
                'action' => 'Voir le devis',
            ],
            'en' => [
                'subject' => 'Your withdrawal is confirmed',
                'intro' => 'We have recorded your withdrawal for quote no. :number, and the provider has been informed.',
                'action' => 'View the quote',
            ],
        ],
    ];
}
