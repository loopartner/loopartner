<?php

namespace App\Support\Moderation;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Contrôle de version des décisions de modération : le modérateur envoie la version de
 * l'élément qu'il a lue. Si l'auteur l'a modifié depuis, la décision est refusée (409) et le
 * modérateur doit relire : aucun contenu n'est validé sans avoir été lu.
 */
final class VersionGuard
{
    /**
     * @throws ConflictHttpException
     */
    public static function assertSame(int $expected, int $actual): void
    {
        if ($expected !== $actual) {
            throw new ConflictHttpException('Cet élément a été modifié depuis votre lecture : rechargez-le avant de décider.');
        }
    }
}
