<?php

namespace App\Support\Moderation;

use App\Models\User;

/**
 * Contrat d'un type de contenu signalable (règlement sur les services numériques, art. 16
 * et 17). Chaque module fournit un adaptateur pour ses contenus (profil, projet, événement,
 * article…), déclaré dans config/moderation.php (targets). Le module Moderation ne connaît que
 * ce contrat : il n'importe jamais les modèles d'un autre module.
 *
 * Une restriction est réversible : le module masque le contenu (colonne
 * moderation_restricted_at, avec l'uuid de la décision) sans toucher à son statut, et la
 * levée le rend tel qu'il était.
 */
interface ModerationTarget
{
    /**
     * Signalable par le formulaire public (POST /moderation/notices). Faux pour les contenus
     * privés (messages, demandes), signalés depuis la messagerie.
     */
    public function publiclyReportable(): bool;

    /**
     * Le contenu tel que ce visiteur (null : anonyme) peut le voir aujourd'hui, ou null s'il
     * n'existe pas, n'est pas visible ou est déjà masqué (le signalant reçoit alors 404).
     */
    public function findVisible(string $ref, ?User $viewer): ?TargetContent;

    /**
     * Le contenu actuel pour la modération, quel que soit son état, ou null s'il a été supprimé.
     */
    public function inspect(string $ref): ?TargetContent;

    /**
     * Masque le contenu (décision de restriction). Sans effet s'il a disparu entre-temps.
     */
    public function restrict(string $ref, string $decisionUuid): void;

    /**
     * Rend visible un contenu masqué par cette décision (levée, recours accepté).
     */
    public function lift(string $ref, string $decisionUuid): void;
}
