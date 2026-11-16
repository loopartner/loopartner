<?php

namespace App\Modules\Moderation\Actions;

use App\Models\User;
use App\Modules\Moderation\Models\Appeal;
use App\Modules\Moderation\Models\Decision;
use App\Modules\Moderation\Models\Notice;

/**
 * Suppression d'un compte (événement AccountDeleting, dans la transaction de DeleteAccount) :
 *
 * - ses signalements restent pour la modération, sans lien avec le compte (reporter_id remis à
 *   null par la clé étrangère, empreinte de signalant effacée) ;
 * - les signalements et décisions qui visent ses contenus restent, sans lien avec le compte
 *   (target_owner_id, owner_id à null) : l'historique de la modération est conservé jusqu'à sa
 *   purge (moderation:prune) ;
 * - ses recours sont supprimés.
 *
 * Les restrictions disparaissent avec les contenus, supprimés par les autres modules.
 */
class ForgetUserModeration
{
    public function handle(User $user): void
    {
        Notice::query()->where('reporter_id', $user->id)->update(['active_key' => null, 'updated_at' => now()]);
        Notice::query()->where('target_owner_id', $user->id)->update(['target_owner_id' => null, 'updated_at' => now()]);
        Decision::query()->where('owner_id', $user->id)->update(['owner_id' => null, 'updated_at' => now()]);
        Appeal::query()->where('appellant_id', $user->id)->delete();
    }
}
