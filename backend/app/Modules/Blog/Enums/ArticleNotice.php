<?php

namespace App\Modules\Blog\Enums;

/**
 * Emails du module Blog envoyés à l'auteur d'un article (ArticleNotification), en français et
 * en anglais.
 *
 * Variable : :title (titre de l'article du destinataire lui-même). Jamais le contenu de
 * l'article, ni le nom d'un modérateur. Le motif d'un refus ou d'une dépublication, écrit par
 * un modérateur, est ajouté à part.
 */
enum ArticleNotice: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Unpublished = 'unpublished';
    case RevisionApproved = 'revision_approved';
    case RevisionRejected = 'revision_rejected';

    /**
     * L'email renvoie vers la page publique de l'article (sinon vers l'article dans l'espace
     * de l'auteur).
     */
    /**
     * Décision restrictive : l'email renvoie aussi vers l'exposé des motifs et le recours
     * (module Moderation, GET /me/moderation/decisions).
     */
    public function isRestrictiveDecision(): bool
    {
        return in_array($this, [self::Rejected, self::Unpublished, self::RevisionRejected], true);
    }

    public function linksToPublicPage(): bool
    {
        return in_array($this, [self::Approved, self::RevisionApproved], true);
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
        'approved' => [
            'fr' => [
                'subject' => 'Votre article est publié',
                'intro' => 'Bonne nouvelle : votre article « :title » a été validé par l\'équipe et il est maintenant en ligne.',
                'action' => 'Voir l\'article',
            ],
            'en' => [
                'subject' => 'Your article is published',
                'intro' => 'Good news: your article “:title” has been approved by the team and is now online.',
                'action' => 'View the article',
            ],
        ],
        'rejected' => [
            'fr' => [
                'subject' => 'Votre article n\'a pas été validé',
                'intro' => 'Votre article « :title » n\'a pas été validé par l\'équipe. Vous pouvez le corriger puis le soumettre de nouveau.',
                'action' => 'Modifier l\'article',
            ],
            'en' => [
                'subject' => 'Your article was not approved',
                'intro' => 'Your article “:title” was not approved by the team. You can edit it and submit it again.',
                'action' => 'Edit the article',
            ],
        ],
        'unpublished' => [
            'fr' => [
                'subject' => 'Votre article a été retiré du blog',
                'intro' => 'Votre article « :title » a été retiré du blog par l\'équipe. Vous pouvez le corriger puis le soumettre de nouveau.',
                'action' => 'Voir l\'article',
            ],
            'en' => [
                'subject' => 'Your article was removed from the blog',
                'intro' => 'Your article “:title” was removed from the blog by the team. You can edit it and submit it again.',
                'action' => 'View the article',
            ],
        ],
        'revision_approved' => [
            'fr' => [
                'subject' => 'Votre modification est publiée',
                'intro' => 'La modification de votre article « :title » a été validée : la nouvelle version est en ligne.',
                'action' => 'Voir l\'article',
            ],
            'en' => [
                'subject' => 'Your change is published',
                'intro' => 'The change to your article “:title” has been approved: the new version is online.',
                'action' => 'View the article',
            ],
        ],
        'revision_rejected' => [
            'fr' => [
                'subject' => 'Votre modification n\'a pas été validée',
                'intro' => 'La modification de votre article « :title » n\'a pas été validée. La version publiée reste en ligne.',
                'action' => 'Voir l\'article',
            ],
            'en' => [
                'subject' => 'Your change was not approved',
                'intro' => 'The change to your article “:title” was not approved. The published version stays online.',
                'action' => 'View the article',
            ],
        ],
    ];
}
