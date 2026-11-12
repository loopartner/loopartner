<?php

namespace App\Modules\Blog\Exceptions;

use RuntimeException;

/**
 * Corps d'article refusé par ArticleBody::parse(). Le message indique l'emplacement de
 * l'erreur (« Bloc 3, segment 2 : … ») sans reprendre le contenu reçu.
 */
class InvalidArticleBody extends RuntimeException {}
