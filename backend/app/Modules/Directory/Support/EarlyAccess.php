<?php

namespace App\Modules\Directory\Support;

use App\Models\User;
use App\Modules\Accounts\Actions\GetEntitlements;
use App\Modules\Directory\Models\Project;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Accès anticipé aux nouveaux projets (décisions 33 et 44) : pendant
 * directory.early_access_hours après sa publication, un projet n'est visible que des
 * prestataires qui ont le droit directory.project_early_access (Premium). Ce n'est pas un
 * classement : la fenêtre filtre, l'ordre reste le même pour tous.
 *
 * Appliqué partout où un prestataire voit un projet : liste, détail, proposition et
 * signalement (ProjectModerationTarget).
 */
final class EarlyAccess
{
    public const FEATURE = 'directory.project_early_access';

    public function __construct(private GetEntitlements $entitlements) {}

    public static function hours(): int
    {
        return max(0, (int) config('directory.early_access_hours'));
    }

    /**
     * Vrai si la fenêtre d'accès anticipé cache encore les nouveaux projets à ce prestataire.
     */
    public function restricts(User $viewer): bool
    {
        return self::hours() > 0 && ! $this->entitlements->handle($viewer)->allows(self::FEATURE);
    }

    /**
     * Limite une requête de projets à ceux que ce prestataire peut voir.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function constrain(Builder $query, User $viewer): Builder
    {
        if ($this->restricts($viewer)) {
            $query->where('published_at', '<=', now()->subHours(self::hours()));
        }

        return $query;
    }

    /**
     * Fin de la fenêtre d'accès anticipé d'un projet publié (null sans fenêtre ni publication).
     */
    public static function endsAt(Project $project): ?CarbonInterface
    {
        if ($project->published_at === null || self::hours() === 0) {
            return null;
        }

        return $project->published_at->copy()->addHours(self::hours());
    }
}
