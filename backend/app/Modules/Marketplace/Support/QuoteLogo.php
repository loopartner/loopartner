<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Accounts\Actions\GetProviderLogo;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Decoders\BinaryImageDecoder;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Copie figée du logo Premium d'un devis (décision 51) : à l'envoi, le logo actuel du profil
 * prestataire est réduit, converti en PNG (lu par dompdf) et encodé en base64, puis gardé dans
 * la ligne du devis (marketplace_quotes.issuer_logo). Le PDF d'un devis envoyé n'utilise que
 * cette copie : changer ou supprimer le logo du profil ne change plus un devis envoyé ou accepté.
 *
 * - Taille : au plus marketplace.quotes.logo.max_encoded_bytes une fois encodée (marge sous
 *   max_allowed_packet, souvent 1 Mo sur XAMPP). Les dimensions sont essayées dans l'ordre de
 *   marketplace.quotes.logo.dimensions ; si aucune ne suffit, le devis part sans logo.
 * - Logo absent ou illisible : null, le devis part sans logo (jamais d'échec de l'envoi).
 * - La copie n'a ni URL ni fichier : elle n'est servie qu'intégrée au PDF des deux parties, et
 *   disparaît avec la ligne du devis (purge, conversation, suppression de compte).
 */
final class QuoteLogo
{
    public function __construct(private GetProviderLogo $providerLogo) {}

    /**
     * Copie encodée (base64 d'un PNG) du logo actuel du prestataire, ou null.
     */
    public function capture(int $providerId): ?string
    {
        $media = $this->providerLogo->handle($providerId);

        if ($media === null) {
            return null;
        }

        try {
            $contents = Storage::disk($media->disk)->get($media->path);

            if ($contents === null) {
                return null;
            }

            $maxBytes = (int) config('marketplace.quotes.logo.max_encoded_bytes');

            foreach ((array) config('marketplace.quotes.logo.dimensions') as $size) {
                $image = ImageManager::gd(strip: true)->read($contents, BinaryImageDecoder::class);
                $encoded = base64_encode((string) $image->scaleDown((int) $size, (int) $size)->toPng());

                if (strlen($encoded) <= $maxBytes) {
                    return $encoded;
                }
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function fingerprint(string $encoded): string
    {
        return hash('sha256', $encoded);
    }
}
