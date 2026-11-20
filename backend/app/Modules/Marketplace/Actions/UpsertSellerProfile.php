<?php

namespace App\Modules\Marketplace\Actions;

use App\Models\User;
use App\Modules\Marketplace\Models\SellerProfile;
use App\Modules\Marketplace\Support\BusinessNumbers;
use App\Support\SecurityLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Crée ou remplace l'identité professionnelle d'un prestataire et enregistre son
 * autocertification (date et version). Les numéros sont enregistrés sans espaces.
 *
 * Changer de numéro d'entreprise efface une vérification d'immatriculation passée (lot 6) :
 * elle ne portait que sur l'ancien numéro.
 *
 * Deux premiers enregistrements simultanés créent la même ligne (user_id unique) : le second
 * recommence et remplace la ligne créée par le premier (représentation complète : la dernière
 * écriture l'emporte), comme UpsertProviderProfile.
 */
class UpsertSellerProfile
{
    private const MAX_ATTEMPTS = 2;

    /**
     * @param  array<string, mixed>  $data  données validées
     */
    public function handle(User $user, array $data): SellerProfile
    {
        return retry(
            self::MAX_ATTEMPTS,
            fn (): SellerProfile => DB::transaction(fn (): SellerProfile => $this->save($user, $data)),
            0,
            fn (\Throwable $e): bool => $e instanceof UniqueConstraintViolationException,
        );
    }

    /**
     * @param  array<string, mixed>  $data  données validées
     */
    private function save(User $user, array $data): SellerProfile
    {
        $profile = SellerProfile::query()->firstOrNew(['user_id' => $user->id]);
        $previousNumber = $profile->exists ? $profile->registration_number : null;

        $content = [];

        foreach ($profile->getFillable() as $field) {
            $content[$field] = $data[$field] ?? null;
        }

        $content['registration_number'] = BusinessNumbers::compact((string) $data['registration_number']);
        $content['vat_number'] = BusinessNumbers::compact(is_string($data['vat_number'] ?? null) ? $data['vat_number'] : null);
        $content['insurance_ten_year'] = (bool) ($data['insurance_ten_year'] ?? false);

        $profile->fill($content)->forceFill([
            'user_id' => $user->id,
            'self_certified_at' => now(),
            'self_certification_version' => (string) config('marketplace.self_certification.version'),
        ]);

        if ($previousNumber !== null && $previousNumber !== $profile->registration_number) {
            $profile->forceFill([
                'registration_verification_status' => null,
                'registration_verification_source' => null,
                'registration_verified_number_hash' => null,
                'registration_verified_at' => null,
                'registration_checked_at' => null,
            ]);
        }

        $profile->save();

        SecurityLog::record('marketplace.seller_profile_updated', ['created' => $profile->wasRecentlyCreated]);

        return $profile;
    }
}
