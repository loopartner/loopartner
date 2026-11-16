<?php

namespace App\Modules\Moderation\Actions;

use App\Models\User;
use App\Modules\Moderation\Models\Notice;
use App\Support\AdminAudit;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Trace qu'un admin a informé les autorités d'un soupçon d'infraction pénale menaçant la vie
 * ou la sécurité de personnes (DSA art. 18), selon la procédure de
 * docs/v1/moderation-rules.md (plateforme PHAROS, à faire valider par le juriste). Rien n'est
 * envoyé automatiquement : l'admin fait le signalement lui-même, puis l'enregistre ici, une
 * fois par signalement (409 ensuite).
 */
class ReferNotice
{
    public const AUTHORITIES = ['pharos', 'police', 'gendarmerie', 'europol', 'other'];

    /**
     * @throws ConflictHttpException
     */
    public function handle(User $admin, Notice $notice, string $authority, ?Request $request = null): Notice
    {
        $notice = DB::transaction(function () use ($admin, $notice): Notice {
            $notice = Notice::query()->whereKey($notice->id)->lockForUpdate()->firstOrFail();

            if ($notice->referred_at !== null) {
                throw new ConflictHttpException('Ce signalement a déjà été transmis aux autorités.');
            }

            $notice->forceFill(['referred_at' => now(), 'referred_by' => $admin->id])->save();

            return $notice;
        });

        AdminAudit::record($admin, 'moderation.notice.referred', 'moderation_notice', $notice->uuid, null, 'Autorité : '.$authority, $request);
        SecurityLog::record('moderation.law_enforcement_referred', [
            'user_id' => $admin->id,
            'notice' => $notice->uuid,
            'authority' => $authority,
        ], request: $request);

        return $notice;
    }
}
