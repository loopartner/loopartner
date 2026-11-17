<?php

namespace App\Modules\Marketplace\Support;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;

/**
 * Conversion en PDF d'un document HTML construit par le module (dompdf, décision 48).
 *
 * Durcissement : le HTML vient uniquement de nos gabarits (texte des utilisateurs échappé),
 * aucune ressource distante (isRemoteEnabled false), ni PHP ni JavaScript, seul le protocole
 * data:// est accepté (logo intégré), chroot limité au dossier des polices fournies avec
 * dompdf (DejaVu Sans : accents et symbole €), cache des polices et fichiers temporaires dans
 * storage/. Le PDF est produit en mémoire, jamais enregistré.
 */
final class Pdf
{
    public static function fromHtml(string $html): string
    {
        $fontDir = base_path('vendor/dompdf/dompdf/lib/fonts');
        $cacheDir = storage_path('framework/cache/dompdf');
        File::ensureDirectoryExists($cacheDir);

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setIsHtml5ParserEnabled(true);
        $options->setAllowedProtocols(['data://' => ['rules' => []]]);
        $options->setChroot([$fontDir]);
        $options->setFontDir($fontDir);
        $options->setFontCache($cacheDir);
        $options->setTempDir($cacheDir);
        $options->setDefaultFont('DejaVu Sans');
        $options->setDefaultPaperSize('a4');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
