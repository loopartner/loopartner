<?php

namespace App\Support\Media;

use Illuminate\Validation\ValidationException;
use Intervention\Image\Decoders\FilePathImageDecoder;
use Intervention\Image\ImageManager;

/**
 * Décode une image envoyée et la réencode en WebP.
 *
 * Le réencodage repart des seuls pixels décodés : les métadonnées EXIF (dont la
 * localisation GPS), les profils et tout contenu caché dans le fichier d'origine
 * disparaissent. L'orientation EXIF est appliquée avant, pour ne pas faire pivoter la photo.
 */
class ImageProcessor
{
    /**
     * Types acceptés, vérifiés sur le contenu réel du fichier (IMAGETYPE_* de getimagesize).
     */
    public const ACCEPTED_TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    public const ACCEPTED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    private ImageManager $manager;

    public function __construct()
    {
        $this->manager = ImageManager::gd(autoOrientation: true, decodeAnimation: false, strip: true);
    }

    /**
     * Lit l'en-tête de l'image sans la décoder.
     *
     * @return array{type: int, width: int, height: int}|null null si ce n'est pas une image reconnue
     */
    public function inspect(string $path): ?array
    {
        $info = @getimagesize($path);

        if ($info === false) {
            return null;
        }

        return ['type' => $info[2], 'width' => $info[0], 'height' => $info[1]];
    }

    /**
     * Réduit l'image aux dimensions maximales (sans agrandir) et l'encode en WebP.
     *
     * @throws ValidationException si l'image ne peut pas être décodée
     */
    public function toWebp(string $path, int $maxWidth, int $maxHeight): ProcessedImage
    {
        try {
            $image = $this->manager->read($path, FilePathImageDecoder::class);
            $image->scaleDown($maxWidth, $maxHeight);
            $encoded = $image->toWebp(quality: (int) config('media.webp_quality', 82));
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'file' => 'L\'image n\'a pas pu être lue. Envoyez un fichier JPEG, PNG ou WebP valide.',
            ]);
        }

        return new ProcessedImage((string) $encoded, $image->width(), $image->height());
    }
}
