<?php

use App\Models\Media;
use App\Models\User;
use App\Modules\Accounts\Models\PremiumGrant;
use App\Modules\Accounts\Models\ProviderProfile;
use App\Modules\Directory\Models\Project;
use App\Modules\Marketplace\Models\Quote;
use App\Modules\Marketplace\Support\QuoteLogo;
use App\Modules\Marketplace\Support\QuotePdf;
use App\Support\Media\MediaPurpose;
use App\Support\Moderation\ModerationTargets;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| Logo Premium figé à l'envoi d'un devis (décision 51) : le PDF d'un devis envoyé ou accepté
| n'utilise que la copie gardée dans le devis, quoi que devienne le logo du profil ou le
| Premium du prestataire ; la copie suit la conservation du devis et n'est jamais exposée.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2027-03-10 12:00:00');
    Notification::fake();
    Storage::fake('public');
    verifiedVatRate('fr.vat.rate.standard', 2000);
});

/**
 * Remplace le logo du profil prestataire par une image unie de la couleur donnée.
 *
 * @param  array{0: int, 1: int, 2: int}  $rgb
 */
function setProviderLogo(User $provider, array $rgb, int $size = 200): Media
{
    $image = imagecreatetruecolor($size, $size);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, ...$rgb));
    ob_start();
    imagepng($image);
    $contents = (string) ob_get_clean();

    $logo = Media::factory()->create(['user_id' => $provider->id, 'purpose' => MediaPurpose::ProviderLogo, 'attached_at' => now()]);
    Storage::disk('public')->put($logo->path, $contents);
    ProviderProfile::query()->where('user_id', $provider->id)->update(['logo_media_id' => $logo->id]);

    return $logo;
}

/**
 * Devis envoyé par un prestataire Premium doté d'un logo : [client, prestataire, devis].
 *
 * @return array{0: User, 1: User, 2: Quote}
 */
function brandedQuote(): array
{
    [$client, $provider, $matchRequest] = acceptedQuoteRequest();
    makePremium($provider);
    setProviderLogo($provider, [200, 30, 30]);

    $uuid = test()->actingAs($provider)->postJson('/api/v1/me/quotes', quotePayload($matchRequest->uuid))->assertCreated()->json('data.uuid');
    test()->actingAs($provider)->postJson("/api/v1/me/quotes/{$uuid}/send")->assertOk();

    return [$client, $provider, Quote::query()->where('uuid', $uuid)->sole()];
}

function quoteHtml(Quote $quote): string
{
    return app(QuotePdf::class)->html($quote->fresh()?->load('lines') ?? $quote, 'fr');
}

it('fige le logo à l\'envoi et en met l\'empreinte dans le contenu accepté', function (): void {
    [, , $quote] = brandedQuote();
    $quote->refresh();

    expect($quote->issuer_logo)->not->toBeNull()
        ->and($quote->issuer_snapshot['branding']['logo'])->toBeTrue()
        ->and($quote->issuer_snapshot['branding']['logo_sha256'])->toBe(QuoteLogo::fingerprint((string) $quote->issuer_logo))
        ->and(quoteHtml($quote))->toContain('<img src="data:image/png;base64,'.$quote->issuer_logo.'"');
});

it('garde le PDF du devis accepté identique quand le prestataire change puis supprime son logo', function (): void {
    [$client, $provider, $quote] = brandedQuote();
    $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/accept", ['content_hash' => $quote->content_hash, 'accept' => true])->assertOk();
    $accepted = quoteHtml($quote);

    setProviderLogo($provider, [30, 30, 200]);
    expect(quoteHtml($quote))->toBe($accepted);

    ProviderProfile::query()->where('user_id', $provider->id)->update(['logo_media_id' => null]);
    Media::query()->where('user_id', $provider->id)->delete();
    expect(quoteHtml($quote))->toBe($accepted);

    $this->actingAs($client)->get("/api/v1/me/quotes/{$quote->uuid}/pdf")->assertOk();
});

it('garde le logo sur les devis déjà envoyés d\'un prestataire qui perd le Premium', function (): void {
    [, $provider, $quote] = brandedQuote();
    PremiumGrant::query()->where('user_id', $provider->id)->update(['ends_at' => now()->subMinute()]);

    expect(quoteHtml($quote))->toContain('<img src="data:image/png;base64,');
});

it('refait la copie à l\'envoi de chaque nouvelle version, avec le logo et les droits du moment', function (): void {
    [, $provider, $quote] = brandedQuote();
    $first = (string) $quote->fresh()?->issuer_logo;

    $revision = $this->actingAs($provider)->postJson("/api/v1/me/quotes/{$quote->uuid}/revise")->assertCreated()->json('data.uuid');
    expect(Quote::query()->where('uuid', $revision)->sole()->issuer_logo)->toBeNull();

    setProviderLogo($provider, [30, 200, 30]);
    $this->actingAs($provider)->postJson("/api/v1/me/quotes/{$revision}/send")->assertOk();

    $sent = Quote::query()->where('uuid', $revision)->sole();
    expect($sent->issuer_logo)->not->toBeNull()->not->toBe($first)
        ->and($quote->fresh()?->issuer_logo)->toBe($first);
});

it('réduit le logo pour tenir dans la taille maximale, et envoie sans logo en dernier recours', function (): void {
    [, $provider, $matchRequest] = acceptedQuoteRequest();
    makePremium($provider);
    $noisy = imagecreatetruecolor(1000, 1000);
    for ($x = 0; $x < 1000; $x += 2) {
        for ($y = 0; $y < 1000; $y += 2) {
            imagesetpixel($noisy, $x, $y, random_int(0, 0xFFFFFF));
        }
    }
    ob_start();
    imagepng($noisy);
    $logo = Media::factory()->create(['user_id' => $provider->id, 'purpose' => MediaPurpose::ProviderLogo, 'attached_at' => now()]);
    Storage::disk('public')->put($logo->path, (string) ob_get_clean());
    ProviderProfile::query()->where('user_id', $provider->id)->update(['logo_media_id' => $logo->id]);

    config(['marketplace.quotes.logo.max_encoded_bytes' => 60_000]);
    $reduced = app(QuoteLogo::class)->capture($provider->id);
    expect($reduced)->not->toBeNull()->and(strlen((string) $reduced))->toBeLessThanOrEqual(60_000);

    config(['marketplace.quotes.logo.max_encoded_bytes' => 10]);
    $uuid = $this->actingAs($provider)->postJson('/api/v1/me/quotes', quotePayload($matchRequest->uuid))->assertCreated()->json('data.uuid');
    $this->actingAs($provider)->postJson("/api/v1/me/quotes/{$uuid}/send")->assertOk();

    $quote = Quote::query()->where('uuid', $uuid)->sole();
    expect($quote->issuer_logo)->toBeNull()
        ->and($quote->issuer_snapshot['branding']['logo'])->toBeFalse()
        ->and($quote->issuer_snapshot['branding'])->toHaveKey('footer');
});

it('sort sans logo un devis envoyé avant la copie figée, sans relire le profil', function (): void {
    [, , $quote] = brandedQuote();
    Quote::query()->whereKey($quote->id)->update(['issuer_logo' => null]);

    expect(quoteHtml($quote))->not->toContain('<img');
});

it('supprime la copie avec le devis lors de la purge', function (): void {
    [$client, , $quote] = brandedQuote();
    $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/decline")->assertOk();

    $this->travelTo(Carbon::parse('2028-03-11 12:00:00'));
    $this->artisan('marketplace:prune')->assertSuccessful();

    expect(Quote::query()->whereKey($quote->id)->exists())->toBeFalse()
        ->and(Quote::query()->whereNotNull('issuer_logo')->count())->toBe(0);
});

it('n\'expose jamais la copie du logo dans l\'API, la modération ni le journal', function (): void {
    [$client, $provider, $quote] = brandedQuote();
    $copy = (string) $quote->fresh()?->issuer_logo;
    makePremium($client);
    $project = Project::query()->findOrFail($quote->project_id);

    $responses = [
        $this->actingAs($client)->getJson("/api/v1/me/quotes/{$quote->uuid}"),
        $this->actingAs($provider)->getJson("/api/v1/me/quotes/{$quote->uuid}"),
        $this->actingAs($client)->getJson('/api/v1/me/quotes?box=received'),
        $this->actingAs($provider)->getJson('/api/v1/me/quotes?box=sent'),
        $this->actingAs($client)->getJson("/api/v1/me/projects/{$project->uuid}/quotes"),
        $this->actingAs($client)->getJson("/api/v1/me/projects/{$project->uuid}/quotes/comparison"),
        $this->actingAs($provider)->getJson('/api/v1/me/compliance/export'),
        $this->actingAs($provider)->getJson('/api/v1/me'),
    ];

    foreach ($responses as $response) {
        expect((string) $response->getContent())->not->toContain('issuer_logo')->not->toContain(substr($copy, 0, 64));
    }

    $inspected = ModerationTargets::get('quote')->inspect($quote->uuid);
    expect($inspected?->text)->not->toContain(substr($copy, 0, 64))
        ->and(json_encode($quote->fresh()?->toArray()))->not->toContain('issuer_logo');

    $log = json_encode(array_map(fn ($record): array => $record->context, securityLog()));
    expect($log)->not->toContain('issuer_logo')->not->toContain(substr($copy, 0, 64));
});
