<?php

namespace App\Modules\Moderation\Enums;

/**
 * Emails du module Moderation (ModerationNotification) et leurs textes. Brouillon à faire valider
 * par le juriste : docs/v1/moderation-rules.md.
 *
 * Confidentialité : jamais l'identité du signalant ni le texte de son signalement dans un email à
 * l'auteur du contenu ; jamais le titre ni le texte du contenu signalé dans un email au signalant
 * (seulement son type et la référence du signalement).
 */
enum ModerationMail: string
{
    // Signalant (catégorie moderation.notices).
    case NoticeReceived = 'notice_received';
    case NoticeDecided = 'notice_decided';

    // Auteur du contenu (catégorie moderation.decisions).
    case DecisionTaken = 'decision_taken';
    case RestrictionLifted = 'restriction_lifted';

    // Auteur d'un recours : catégorie selon qu'il est signalant ou auteur du contenu.
    case AppealDecided = 'appeal_decided';

    /**
     * @return array{subject: string, intro: string, action: string, outro?: string}
     */
    public function texts(string $locale): array
    {
        return self::TEXTS[$this->value][$locale] ?? self::TEXTS[$this->value]['fr'];
    }

    /**
     * Phrases communes : :type (type de contenu), :date (date limite), :reference (article des
     * CGU), :clause (intitulé de la clause), :law (référence juridique).
     *
     * @return array<string, string>
     */
    public static function common(string $locale): array
    {
        return self::COMMON[$locale] ?? self::COMMON['fr'];
    }

    /**
     * @var array<string, array<string, string>>
     */
    private const COMMON = [
        'fr' => [
            'greeting' => 'Bonjour,',
            'salutation' => 'L\'équipe Loopartner',
            'reference' => 'Référence : :uuid',
            'measure_restrict' => 'Décision : le contenu n\'est plus visible sur Loopartner.',
            'measure_none' => 'Décision : aucune mesure n\'a été prise sur ce contenu.',
            'source_notice' => 'Origine : un ou plusieurs signalements.',
            'source_initiative' => 'Origine : une vérification de l\'équipe de modération.',
            'source_review' => 'Origine : l\'examen de votre contenu avant sa publication.',
            'ground_terms' => 'Fondement : conditions générales d\'utilisation, :clause:reference.',
            'ground_illegal' => 'Fondement : contenu illicite au regard de :law.',
            'facts' => 'Faits et motifs : :facts',
            'correction' => 'Ce que vous pouvez corriger : :hint',
            'human' => 'Cette décision a été prise par un membre de l\'équipe de modération, sans outil automatisé.',
            'scope' => 'Portée : partout où Loopartner est accessible, jusqu\'à la levée de la décision.',
            'appeal' => 'Vous pouvez contester cette décision gratuitement jusqu\'au :date, depuis la page de la décision. Après une correction, vous pouvez aussi demander son réexamen par ce même recours.',
            'appeal_notice' => 'Vous pouvez contester cette décision gratuitement jusqu\'au :date, depuis la page de votre signalement.',
            'court' => 'Vous pouvez aussi saisir la juridiction compétente.',
            'outcome' => 'Motif : :reason',
        ],
        'en' => [
            'greeting' => 'Hello,',
            'salutation' => 'The Loopartner team',
            'reference' => 'Reference: :uuid',
            'measure_restrict' => 'Decision: the content is no longer visible on Loopartner.',
            'measure_none' => 'Decision: no action has been taken on this content.',
            'source_notice' => 'Origin: one or more reports.',
            'source_initiative' => 'Origin: a check by the moderation team.',
            'source_review' => 'Origin: the review of your content before publication.',
            'ground_terms' => 'Ground: terms of use, :clause:reference.',
            'ground_illegal' => 'Ground: illegal content under :law.',
            'facts' => 'Facts and reasons: :facts',
            'correction' => 'What you can correct: :hint',
            'human' => 'This decision was taken by a member of the moderation team, without automated tools.',
            'scope' => 'Scope: wherever Loopartner is available, until the decision is lifted.',
            'appeal' => 'You can contest this decision free of charge until :date, from the decision page. After a correction, you can also ask for it to be reviewed through the same appeal.',
            'appeal_notice' => 'You can contest this decision free of charge until :date, from the page of your report.',
            'court' => 'You can also bring the matter before the competent court.',
            'outcome' => 'Reason: :reason',
        ],
    ];

    /**
     * @var array<string, array<string, array{subject: string, intro: string, action: string, outro?: string}>>
     */
    private const TEXTS = [
        'notice_received' => [
            'fr' => ['subject' => 'Nous avons reçu votre signalement', 'intro' => 'Nous avons bien reçu votre signalement concernant un contenu de type « :type ». Nous l\'examinons et vous informerons de notre décision.', 'action' => 'Suivre mon signalement', 'outro' => 'Gardez cet email : le lien ci-dessus est le seul moyen de suivre votre signalement.'],
            'en' => ['subject' => 'We have received your report', 'intro' => 'We have received your report about a piece of content of type ":type". We are reviewing it and will inform you of our decision.', 'action' => 'Follow my report', 'outro' => 'Keep this email: the link above is the only way to follow your report.'],
        ],
        'notice_decided' => [
            'fr' => ['subject' => 'Décision sur votre signalement', 'intro' => 'Votre signalement concernant un contenu de type « :type » a été examiné par l\'équipe de modération.', 'action' => 'Voir mon signalement'],
            'en' => ['subject' => 'Decision on your report', 'intro' => 'Your report about a piece of content of type ":type" has been reviewed by the moderation team.', 'action' => 'View my report'],
        ],
        'decision_taken' => [
            'fr' => ['subject' => 'Décision de modération concernant votre contenu', 'intro' => 'Votre contenu « :label » (:type) n\'est plus visible sur Loopartner à la suite d\'une décision de l\'équipe de modération.', 'action' => 'Voir la décision et contester'],
            'en' => ['subject' => 'Moderation decision about your content', 'intro' => 'Your content ":label" (:type) is no longer visible on Loopartner following a decision by the moderation team.', 'action' => 'View the decision and appeal'],
        ],
        'restriction_lifted' => [
            'fr' => ['subject' => 'Votre contenu est de nouveau visible', 'intro' => 'Votre contenu « :label » (:type) est de nouveau visible sur Loopartner : la décision de modération a été levée.', 'action' => 'Voir la décision'],
            'en' => ['subject' => 'Your content is visible again', 'intro' => 'Your content ":label" (:type) is visible again on Loopartner: the moderation decision has been lifted.', 'action' => 'View the decision'],
        ],
        'appeal_decided' => [
            'fr' => ['subject' => 'Décision sur votre recours', 'intro' => 'Votre recours a été examiné par l\'équipe de modération. :outcome', 'action' => 'Voir le détail'],
            'en' => ['subject' => 'Decision on your appeal', 'intro' => 'Your appeal has been reviewed by the moderation team. :outcome', 'action' => 'View details'],
        ],
    ];

    /**
     * Issue d'un recours, insérée dans l'introduction.
     *
     * @var array<string, array{upheld: string, upheld_review: string, upheld_notifier: string, rejected: string}>
     */
    public const APPEAL_OUTCOMES = [
        'fr' => [
            'upheld' => 'Il est accepté : la décision est annulée.',
            'upheld_review' => 'Il est accepté : vous pouvez soumettre de nouveau votre contenu, qui sera réexaminé en tenant compte de votre recours.',
            'upheld_notifier' => 'Il est accepté : votre signalement est examiné à nouveau.',
            'rejected' => 'Il est rejeté : la décision est maintenue.',
        ],
        'en' => [
            'upheld' => 'It has been accepted: the decision is reversed.',
            'upheld_review' => 'It has been accepted: you can submit your content again, and it will be reviewed taking your appeal into account.',
            'upheld_notifier' => 'It has been accepted: your report is being reviewed again.',
            'rejected' => 'It has been rejected: the decision stands.',
        ],
    ];
}
