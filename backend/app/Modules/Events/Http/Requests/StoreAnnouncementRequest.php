<?php

namespace App\Modules\Events\Http\Requests;

use App\Modules\Events\Models\EventAnnouncement;
use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;
use App\Rules\SafeLinksInText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /me/events/{uuid}/announcements : texte brut ; un lien éventuel doit respecter les règles
 * des liens externes, et l'annonce attend alors la validation d'un admin. L'autorisation
 * (organisateur) est vérifiée par le contrôleur.
 */
class StoreAnnouncementRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => [
                'required', 'string',
                'min:'.EventAnnouncement::BODY_MIN_LENGTH,
                'max:'.EventAnnouncement::BODY_MAX_LENGTH,
                new NoHtml, new NoInvisibleCharacters, new SafeLinksInText,
            ],
        ];
    }
}
