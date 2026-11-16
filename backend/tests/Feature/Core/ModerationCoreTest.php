<?php

use App\Models\User;
use App\Support\Moderation\ModerationTarget;
use App\Support\Moderation\ModerationTargets;
use App\Support\Moderation\TargetContent;
use App\Support\Moderation\VersionGuard;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/*
| Briques de modération du noyau : registre des contenus signalables (config/moderation.php)
| et contrôle de version par empreinte.
*/

it('refuse une empreinte de version différente (409) et accepte la même', function (): void {
    $version = TargetContent::fingerprint(['title' => 'Un titre']);

    VersionGuard::assertSameFingerprint($version, TargetContent::fingerprint(['title' => 'Un titre']));

    expect(fn () => VersionGuard::assertSameFingerprint($version, TargetContent::fingerprint(['title' => 'Un autre titre'])))
        ->toThrow(ConflictHttpException::class);
});

it('résout un adaptateur déclaré en configuration et refuse un type inconnu ou mal déclaré', function (): void {
    $adapter = new class implements ModerationTarget
    {
        public function publiclyReportable(): bool
        {
            return true;
        }

        public function findVisible(string $ref, ?User $viewer): ?TargetContent
        {
            return null;
        }

        public function inspect(string $ref): ?TargetContent
        {
            return null;
        }

        public function restrict(string $ref, string $decisionUuid): void {}

        public function lift(string $ref, string $decisionUuid): void {}
    };

    app()->instance('test.moderation.adapter', $adapter);
    config(['moderation.targets' => ['sample' => 'test.moderation.adapter']]);

    expect(ModerationTargets::get('sample'))->toBe($adapter)
        ->and(ModerationTargets::publicTypes())->toBe(['sample'])
        ->and(ModerationTargets::has('unknown'))->toBeFalse()
        ->and(fn () => ModerationTargets::get('unknown'))->toThrow(InvalidArgumentException::class);

    config(['moderation.targets' => ['broken' => stdClass::class]]);

    expect(fn () => ModerationTargets::get('broken'))->toThrow(LogicException::class);
});

it('assemble une copie textuelle sans les champs vides', function (): void {
    expect(TargetContent::joinText(['Titre' => 'Atelier', 'Description' => '  ', 'Ville' => null, 'Pays' => 'FR']))
        ->toBe("Titre : Atelier\nPays : FR");
});
