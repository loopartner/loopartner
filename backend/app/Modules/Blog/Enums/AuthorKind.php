<?php

namespace App\Modules\Blog\Enums;

/**
 * Type d'auteur d'un article, fixé à sa création d'après le rôle de l'auteur.
 *
 * - editorial : l'équipe (rédacteurs, admins), présentée comme « la rédaction », publie sans
 *   validation, liens sans nofollow ;
 * - provider : un prestataire, présenté par son profil public ;
 * - entrepreneur : présenté par le nom d'auteur choisi pour l'article.
 *
 * Les liens des auteurs extérieurs à l'équipe portent toujours rel="nofollow ugc", qu'ils soient
 * premium ou non (voir ArticleBodyPresenter).
 */
enum AuthorKind: string
{
    case Editorial = 'editorial';
    case Provider = 'provider';
    case Entrepreneur = 'entrepreneur';

    public function isEditorial(): bool
    {
        return $this === self::Editorial;
    }
}
