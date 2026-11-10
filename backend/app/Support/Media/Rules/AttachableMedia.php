<?php

namespace App\Support\Media\Rules;

use App\Models\User;
use App\Support\Media\MediaLibrary;
use App\Support\Media\MediaPurpose;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valide l'uuid d'un média à rattacher à un champ (avatar, logo…) : le média existe,
 * appartient à l'utilisateur, a l'usage attendu, et il est libre ou déjà rattaché à ce champ.
 *
 * MediaLibrary::replace() refait la même vérification sous verrou au moment du rattachement.
 * null est géré par la règle "nullable" du champ (retrait du média).
 */
class AttachableMedia implements ValidationRule
{
    public function __construct(
        private User $owner,
        private MediaPurpose $purpose,
        private ?int $currentMediaId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $library = app(MediaLibrary::class);

        $media = is_string($value) ? $library->findByUuid($value) : null;

        if (! $library->isAttachable($media, $this->owner, $this->purpose, $this->currentMediaId)) {
            $fail(MediaLibrary::notAttachableMessage());
        }
    }
}
