<?php

namespace App\Modules\Compliance\Support;

use App\Models\User;
use App\Modules\Compliance\Enums\RuleKind;
use App\Modules\Compliance\Enums\RuleStatus;
use App\Modules\Compliance\Jobs\RefreshCountryCalendars;
use App\Modules\Compliance\Models\ComplianceRule;
use App\Support\AdminAudit;
use App\Support\Moderation\VersionGuard;
use App\Support\SecurityLog;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Briques communes aux Actions d'administration des règles : verrou de la ligne, contrôle de
 * l'état et de la version lue, traces (journal d'audit en base et canal security).
 *
 * Le canal security ne reçoit que des identifiants (uuid, code, pays, version), jamais un motif
 * ni une note : ceux-ci vont dans le journal d'audit.
 */
final class RuleAdministration
{
    public const AUDIT_SUBJECT = 'compliance_rule';

    /**
     * Relit la règle sous verrou, vérifie son état et la version lue par l'admin.
     *
     * @throws ConflictHttpException
     */
    public static function lock(ComplianceRule $rule, RuleStatus $expected, ?int $version, string $conflictMessage): ComplianceRule
    {
        $locked = ComplianceRule::query()->whereKey($rule->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== $expected) {
            throw new ConflictHttpException($conflictMessage);
        }

        if ($version !== null) {
            VersionGuard::assertSame($version, $locked->version);
        }

        return $locked;
    }

    /**
     * Une règle d'échéance vérifiée ou retirée change le calendrier des entreprises du pays :
     * recalcul en file d'attente, une fois la transaction validée. Les seuils et les taux sont
     * lus à chaque calcul : rien à recalculer pour eux.
     */
    public static function refreshCalendarsAfterCommit(ComplianceRule $rule): void
    {
        if ($rule->kind === RuleKind::Deadline) {
            RefreshCountryCalendars::dispatch($rule->country)->afterCommit();
        }
    }

    public static function record(User $admin, string $action, ComplianceRule $rule, ?string $reason = null): void
    {
        AdminAudit::record($admin, 'compliance.rule.'.$action, self::AUDIT_SUBJECT, $rule->uuid, $rule->version, $reason);
        SecurityLog::record('compliance.rule_'.$action, [
            'user_id' => $admin->id,
            'rule' => $rule->uuid,
            'code' => $rule->code,
            'country' => $rule->country,
            'version' => $rule->version,
        ]);
    }
}
