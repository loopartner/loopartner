<?php

namespace App\Modules\Moderation\Enums;

/**
 * Origine d'une décision (DSA art. 17 §3 b) : un ou plusieurs signalements, une enquête
 * d'initiative de l'équipe, ou la modération avant publication des événements et des
 * articles (décision prise dans le module Events ou Blog, dont il gère seul les effets).
 */
enum DecisionSource: string
{
    case Notice = 'notice';
    case OwnInitiative = 'own_initiative';
    case PrePublicationReview = 'pre_publication_review';
}
