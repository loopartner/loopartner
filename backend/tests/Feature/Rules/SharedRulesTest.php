<?php

use App\Rules\CountryCode;
use App\Rules\CurrencyCode;
use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;
use App\Support\Locale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

function passesRule(object $rule, mixed $value): bool
{
    return Validator::make(['field' => $value], ['field' => [$rule]])->passes();
}

describe('NoHtml', function (): void {
    it('accepte du texte brut, y compris un "<" isolé', function (string $value): void {
        expect(passesRule(new NoHtml, $value))->toBeTrue();
    })->with(['Bonjour', 'a < b', 'prix <10 €', "Ligne 1\nLigne 2"]);

    it('refuse les balises, commentaires et instructions HTML', function (string $value): void {
        expect(passesRule(new NoHtml, $value))->toBeFalse();
    })->with(['<b>gras</b>', '</p>', '<!-- commentaire -->', '<?php echo 1; ?>', '<script>alert(1)</script>']);
});

describe('NoInvisibleCharacters', function (): void {
    it('accepte du texte brut, les accents, les emojis, la tabulation et les retours à la ligne', function (string $value): void {
        expect(passesRule(new NoInvisibleCharacters, $value))->toBeTrue();
    })->with([
        'texte' => "Zoé L'Hermitte",
        'emoji' => "Atelier \u{1F3A8}",
        'emoji composé avec U+200D (famille)' => "Famille \u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}",
        'emoji composé avec U+200D (développeuse)' => "Développeuse \u{1F469}\u{200D}\u{1F4BB}",
        'persan avec U+200C (« می‌خواهم »)' => "\u{0645}\u{06CC}\u{200C}\u{062E}\u{0648}\u{0627}\u{0647}\u{0645}",
        'arabe' => 'مرحبا',
        'tabulation' => "Prix\t500 €",
        'LF' => "Ligne 1\nLigne 2",
        'CRLF' => "Ligne 1\r\nLigne 2",
    ]);

    it('refuse les caractères invisibles et de contrôle', function (string $character): void {
        expect(passesRule(new NoInvisibleCharacters, "fact{$character}ure"))->toBeFalse();
    })->with([
        'NUL' => "\u{0000}",
        'BEL' => "\u{0007}",
        'échappement' => "\u{001B}",
        'DEL' => "\u{007F}",
        'C1' => "\u{0085}",
        'marque de lettre arabe' => "\u{061C}",
        'espace de largeur nulle' => "\u{200B}",
        'marque gauche-droite' => "\u{200E}",
        'marque droite-gauche' => "\u{200F}",
        'enchâssement' => "\u{202A}",
        'forçage droite-gauche' => "\u{202E}",
        'gluon de mots' => "\u{2060}",
        'isolat' => "\u{2066}",
        'fin d\'isolat' => "\u{2069}",
        'BOM' => "\u{FEFF}",
    ]);

    it('refuse une chaîne UTF-8 invalide', function (): void {
        expect(passesRule(new NoInvisibleCharacters, "texte \xC3\x28"))->toBeFalse();
    });
});

describe('CountryCode', function (): void {
    it('accepte un code ISO 3166-1 alpha-2 en majuscules', function (): void {
        expect(passesRule(new CountryCode, 'FR'))->toBeTrue();
    });

    it('refuse un code inconnu, en minuscules ou d\'un autre type', function (mixed $value): void {
        expect(passesRule(new CountryCode, $value))->toBeFalse();
    })->with(['fr', 'XX', 'FRA', 33]);
});

describe('CurrencyCode', function (): void {
    it('accepte un code ISO 4217 en cours', function (string $value): void {
        expect(passesRule(new CurrencyCode, $value))->toBeTrue();
    })->with(['EUR', 'USD', 'XOF', 'CHF']);

    it('refuse un code inconnu, retiré, en minuscules ou d\'un autre type', function (mixed $value): void {
        expect(passesRule(new CurrencyCode, $value))->toBeFalse();
    })->with(['eur', 'FRF', 'XXX', 'EURO', 978]);
});

describe('Locale::fromRequest', function (): void {
    it('résout la langue de la requête', function (array $query, ?string $acceptLanguage, string $expected): void {
        $request = Request::create('/', 'GET', $query);
        // Request::create() ajoute un Accept-Language par défaut ("en-us,en;q=0.5").
        $request->headers->remove('Accept-Language');
        if ($acceptLanguage !== null) {
            $request->headers->set('Accept-Language', $acceptLanguage);
        }

        expect(Locale::fromRequest($request))->toBe($expected);
    })->with([
        'paramètre locale' => [['locale' => 'en'], 'fr-FR', 'en'],
        'paramètre invalide ignoré' => [['locale' => 'de'], 'en', 'en'],
        'Accept-Language régional' => [[], 'en-US,en;q=0.9', 'en'],
        'Accept-Language par priorité' => [[], 'de-DE, en;q=0.5, fr;q=0.8', 'fr'],
        'langue non prise en charge' => [[], 'de-DE', 'fr'],
        'aucune indication' => [[], null, 'fr'],
    ]);
});
