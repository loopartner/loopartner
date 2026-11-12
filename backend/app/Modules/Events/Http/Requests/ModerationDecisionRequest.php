<?php

namespace App\Modules\Events\Http\Requests;

use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Décision de modération (POST /admin/…/approve, reject, cancel).
 *
 * - version : version de l'élément vue par l'admin (approve et reject) : si l'organisateur l'a
 *   modifié depuis, la décision est refusée (409).
 * - reason : motif obligatoire d'un refus ou d'une annulation, communiqué à l'organisateur.
 *
 * L'accès (admin avec 2FA) est vérifié par le middleware EnsureModerator.
 */
class ModerationDecisionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Méthode du contrôleur : approveEvent, rejectRevision, cancelEvent…
        $decision = (string) $this->route()?->getActionMethod();

        return [
            'version' => str_starts_with($decision, 'cancel') ? ['prohibited'] : ['required', 'integer', 'min:1'],
            'reason' => str_starts_with($decision, 'approve')
                ? ['prohibited']
                : ['required', 'string', 'min:10', 'max:1000', new NoHtml, new NoInvisibleCharacters],
        ];
    }
}
