<?php

namespace App\Modules\Blog\Rules;

use App\Modules\Blog\Exceptions\InvalidArticleBody;
use App\Modules\Blog\Support\ArticleBody;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Corps d'un article : document JSON de blocs valide (ArticleBody). Le message indique
 * l'emplacement de l'erreur (« Bloc 3, segment 2 : … »).
 */
class ValidArticleBody implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            ArticleBody::parse($value);
        } catch (InvalidArticleBody $e) {
            $fail($e->getMessage());
        }
    }
}
