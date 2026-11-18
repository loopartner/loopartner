<?php

namespace App\Modules\Workspaces\Http\Requests;

use App\Models\User;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Support\Workspaces;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * Base des requêtes d'écriture du module, vérifiées **avant la validation** :
 * - rôle : particulier ou entrepreneur (403) ;
 * - objet : le workspace de l'URL ({uuid}) doit être celui de l'utilisateur (404 sinon, admins
 *   compris), pour qu'une requête invalide sur le workspace d'autrui ne réponde jamais 422.
 */
abstract class WorkspaceRequest extends FormRequest
{
    private ?Workspace $workspace = null;

    public function authorize(): Response
    {
        $response = Gate::inspect('use', Workspace::class);

        if ($response->allowed() && is_string($this->route('uuid'))) {
            /** @var User $user */
            $user = $this->user();
            $this->workspace = app(Workspaces::class)->own($user, $this->route('uuid'));
        }

        return $response;
    }

    /**
     * Le workspace de l'URL, déjà contrôlé par authorize().
     */
    public function workspace(): Workspace
    {
        return $this->workspace ?? throw new LogicException('Aucun workspace dans cette route.');
    }

    /**
     * Modification partielle (PATCH) ?
     */
    protected function partial(): bool
    {
        return $this->isMethod('PATCH');
    }
}
