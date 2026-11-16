<?php

namespace App\Modules\Accounts\Support;

use App\Models\User;
use App\Modules\Accounts\Models\ProviderProfile;
use App\Support\Moderation\ModerationTarget;
use App\Support\Moderation\Restriction;
use App\Support\Moderation\TargetContent;
use Illuminate\Support\Facades\DB;

/**
 * Profil prestataire signalable (module Moderation), désigné par son slug, stable. Masqué, il
 * est traité comme non publié partout (scope published()).
 */
class ProviderProfileModerationTarget implements ModerationTarget
{
    public function publiclyReportable(): bool
    {
        return true;
    }

    public function findVisible(string $ref, ?User $viewer): ?TargetContent
    {
        $profile = ProviderProfile::query()->published()->with('logo')->where('slug', $ref)->first();

        return $profile === null ? null : self::content($profile);
    }

    public function inspect(string $ref): ?TargetContent
    {
        $profile = ProviderProfile::query()->with('logo')->where('slug', $ref)->first();

        return $profile === null ? null : self::content($profile);
    }

    public function restrict(string $ref, string $decisionUuid): void
    {
        DB::transaction(function () use ($ref, $decisionUuid): void {
            $profile = ProviderProfile::query()->where('slug', $ref)->lockForUpdate()->first();

            if ($profile !== null) {
                Restriction::apply($profile, $decisionUuid);
            }
        });
    }

    public function lift(string $ref, string $decisionUuid): void
    {
        DB::transaction(function () use ($ref, $decisionUuid): void {
            $profile = ProviderProfile::query()->where('slug', $ref)->lockForUpdate()->first();

            if ($profile !== null) {
                Restriction::lift($profile, $decisionUuid);
            }
        });
    }

    private static function content(ProviderProfile $profile): TargetContent
    {
        $fields = [
            'display_name' => $profile->display_name,
            'headline' => $profile->headline,
            'bio' => $profile->bio,
            'city' => $profile->city,
            'country' => $profile->country,
            'website' => $profile->website,
            'logo' => $profile->logo_media_id,
        ];

        $logo = $profile->logo?->url();

        return new TargetContent(
            type: 'provider_profile',
            ref: $profile->slug,
            ownerId: $profile->user_id,
            label: $profile->display_name,
            text: TargetContent::joinText([
                'Nom' => $profile->display_name,
                'Accroche' => $profile->headline,
                'Présentation' => $profile->bio,
                'Ville' => $profile->city,
                'Pays' => $profile->country,
                'Site' => $profile->website,
            ]),
            version: TargetContent::fingerprint($fields),
            restrictedBy: Restriction::decisionOf($profile),
            images: $logo !== null ? [$logo] : [],
            publicPath: '/providers/'.$profile->slug,
        );
    }
}
