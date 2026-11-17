<?php

namespace App\Modules\Marketplace\Actions;

use App\Models\User;
use App\Modules\Marketplace\Models\Quote;
use App\Modules\Moderation\Actions\SubmitNotice;
use App\Modules\Moderation\Support\NoticeReceipt;
use App\Support\SecurityLog;

/**
 * Le client signale un devis reçu (décision 45). Le signalement rejoint ceux de tous les
 * contenus (Action publique Moderation\Actions\SubmitNotice) : la modération copie le contenu,
 * et un même devis n'est signalé qu'une fois par utilisateur tant qu'il est en cours d'examen
 * (409). Le journal security ne reçoit que des identifiants et la catégorie.
 */
class ReportQuote
{
    public function __construct(private SubmitNotice $submitNotice) {}

    /**
     * @param  array{category: string, comment?: string|null}  $data
     */
    public function handle(User $reporter, Quote $quote, array $data): NoticeReceipt
    {
        $receipt = $this->submitNotice->inApp($reporter, 'quote', $quote->uuid, $data['category'], 'quote', $data['comment'] ?? null);

        SecurityLog::record('moderation.report_created', [
            'report' => $receipt->uuid,
            'target_type' => 'quote',
            'target' => $quote->uuid,
            'reason' => $data['category'],
            'reported_user_id' => $quote->provider_id,
        ]);

        return $receipt;
    }
}
