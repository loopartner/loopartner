<?php

namespace App\Modules\Blog\Http\Requests;

use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Décision de modération du Blog (POST /admin/articles/…, /admin/article-revisions/…).
 *
 * - version : version de l'élément lue par le modérateur (validation et refus) : si l'auteur l'a
 *   modifié depuis, la décision est refusée (409). Pas de version pour une dépublication.
 * - reason : motif obligatoire d'un refus ou d'une dépublication, communiqué à l'auteur.
 *
 * L'accès (rédacteur ou admin avec 2FA de session) est vérifié par le middleware EnsureModerator.
 */
class ModerationDecisionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Méthode du contrôleur : approveArticle, rejectRevision, unpublishArticle…
        $decision = (string) $this->route()?->getActionMethod();

        return [
            'version' => str_starts_with($decision, 'unpublish') ? ['prohibited'] : ['required', 'integer', 'min:1'],
            'reason' => str_starts_with($decision, 'approve')
                ? ['prohibited']
                : ['required', 'string', 'min:10', 'max:1000', new NoHtml, new NoInvisibleCharacters],
        ];
    }
}
