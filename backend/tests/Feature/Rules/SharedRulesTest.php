<?php

use App\Rules\CountryCode;
use App\Rules\CurrencyCode;
use App\Rules\NoHtml;
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
