<?php

namespace App\Modules\Accounts\Http\Resources;

use App\Models\User;
use App\Modules\Accounts\Actions\GetPremiumStatus;
use App\Modules\Blog\Actions\GetArticleQuota;
use App\Support\TeamTwoFactor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Utilisateur connecté, tel qu'il se voit lui-même.
 *
 * Chaque champ est listé explicitement : jamais de mot de passe, de secret 2FA,
 * de codes de récupération ni de jeton "remember me".
 *
 * @mixin User
 */
class MeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'roles' => $this->getRoleNames()->values()->all(),
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'country' => $this->country,
            'avatar_url' => $this->avatar?->url(),
            'email_verified' => $this->hasVerifiedEmail(),
            'two_factor_enabled' => $this->two_factor_confirmed_at !== null,
            // Équipe (admin, rédacteur) : 2FA obligatoire, le frontend impose son activation.
            'two_factor_required' => TeamTwoFactor::isTeamMember($this->resource),
            // Statut premium en cours, sans motif ni admin.
            'premium' => app(GetPremiumStatus::class)->handle($this->id),
            // Quota gratuit d'articles (module Blog) : prestataires et entrepreneurs, null sinon.
            'article_quota' => app(GetArticleQuota::class)->handle($this->resource),
        ];
    }
}
