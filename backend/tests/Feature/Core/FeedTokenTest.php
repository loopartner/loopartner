<?php

use App\Support\Calendar\FeedToken;

/*
| Jeton d'abonnement à un calendrier (App\Support\Calendar\FeedToken).
*/

it('génère un jeton de 56 caractères dont seule l\'empreinte du secret est gardée', function (): void {
    $token = FeedToken::generate();

    expect($token['token'])->toMatch('/^'.FeedToken::PATTERN.'$/')
        ->and(FeedToken::selector($token['token']))->toBe($token['selector'])
        ->and($token['hash'])->toHaveLength(64)
        ->and($token['hash'])->not->toContain(substr($token['token'], FeedToken::SELECTOR_LENGTH));
});

it('reconnaît le jeton et refuse un secret modifié ou une empreinte absente', function (): void {
    $token = FeedToken::generate();
    $altered = substr($token['token'], 0, -1).(str_ends_with($token['token'], 'a') ? 'b' : 'a');

    expect(FeedToken::matches($token['hash'], $token['token']))->toBeTrue()
        ->and(FeedToken::matches($token['hash'], $altered))->toBeFalse()
        ->and(FeedToken::matches(null, $token['token']))->toBeFalse();
});
