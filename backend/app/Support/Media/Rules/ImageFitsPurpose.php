<?php

namespace App\Support\Media\Rules;

use App\Support\Media\ImageProcessor;
use App\Support\Media\PurposeLimits;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Vérifie, sur l'en-tête réel du fichier et avant tout décodage, que l'image est un JPEG,
 * un PNG ou un WebP, qu'elle atteint les dimensions minimales de l'usage et qu'elle ne
 * dépasse pas le plafond de pixels (protection contre les "bombes de décompression").
 */
class ImageFitsPurpose implements ValidationRule
{
    public function __construct(private PurposeLimits $limits) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        $info = app(ImageProcessor::class)->inspect((string) $value->getRealPath());

        if ($info === null || ! in_array($info['type'], ImageProcessor::ACCEPTED_TYPES, true)) {
            $fail('Le fichier doit être une image JPEG, PNG ou WebP.');

            return;
        }

        $maxSide = (int) config('media.max_side');
        $maxPixels = (int) config('media.max_pixels');

        if ($info['width'] > $maxSide || $info['height'] > $maxSide || $maxPixels < $info['width'] * $info['height']) {
            $fail("L'image est trop grande : {$maxSide} pixels de côté et ".intdiv($maxPixels, 1_000_000).' mégapixels au maximum.');

            return;
        }

        if ($info['width'] < $this->limits->minWidth || $info['height'] < $this->limits->minHeight) {
            $fail("L'image est trop petite : {$this->limits->minWidth} × {$this->limits->minHeight} pixels au minimum.");
        }
    }
}
