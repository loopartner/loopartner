<?php

namespace App\Modules\Accounts\Http\Requests;

use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Administration du statut premium (/admin/premium-grants). L'accès (admin avec 2FA de
 * session) est vérifié par le middleware EnsureModerator.
 *
 * - index  : email (obligatoire) du compte dont on veut l'historique ;
 * - store  : email, starts_at (facultatif, maintenant par défaut), ends_at (facultatif, sans
 *   fin par défaut), reason (motif, écrit dans le journal d'audit) ;
 * - revoke : reason.
 *
 * Les dates sont en UTC (ISO 8601, par exemple "2027-01-01T00:00:00Z").
 */
class PremiumGrantRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $reason = ['required', 'string', 'min:10', 'max:1000', new NoHtml, new NoInvisibleCharacters];
        $email = ['required', 'string', 'email', 'max:254'];

        return match ((string) $this->route()?->getActionMethod()) {
            'index' => ['email' => $email],
            'store' => [
                'email' => $email,
                'starts_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:-5 minutes'],
                'ends_at' => ['sometimes', 'nullable', 'date', 'after:now', 'after:starts_at'],
                'reason' => $reason,
            ],
            default => ['reason' => $reason],
        };
    }
}
