<?php

namespace App\Support\Media;

/**
 * Image réencodée en WebP, prête à être stockée.
 */
final readonly class ProcessedImage
{
    public function __construct(
        public string $contents,
        public int $width,
        public int $height,
    ) {}
}
