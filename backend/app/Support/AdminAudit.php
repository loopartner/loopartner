<?php

namespace App\Support;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Journal d'audit des décisions d'administration (table admin_audit_logs), en plus du canal
 * security : il est conservé plus longtemps (security.retention.admin_audit_months) et
 * consultable par élément (un événement, une révision…).
 *
 * Chaque entrée : l'admin, l'action ("events.event.approved"…), l'élément visé (type et uuid,
 * jamais d'id interne), sa version au moment de la décision, le motif éventuel, l'IP et la route.
 * Le motif est écrit par l'admin pour l'auteur de l'élément : il ne doit pas contenir de donnée
 * personnelle.
 */
final class AdminAudit
{
    public static function record(User $admin, string $action, string $subjectType, string $subjectUuid, ?int $version = null, ?string $reason = null, ?Request $request = null): AdminAuditLog
    {
        $request ??= request();

        $entry = new AdminAuditLog;
        $entry->forceFill([
            'admin_id' => $admin->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_uuid' => $subjectUuid,
            'version' => $version,
            'reason' => $reason,
            'metadata' => [
                'ip' => $request->ip(),
                'route' => $request->method().' /'.ltrim($request->path(), '/'),
            ],
            'created_at' => now(),
        ])->save();

        return $entry;
    }

    /**
     * Décisions prises sur un élément, de la plus ancienne à la plus récente.
     *
     * @return Collection<int, AdminAuditLog>
     */
    public static function historyOf(string $subjectType, string $subjectUuid): Collection
    {
        return AdminAuditLog::query()
            ->where('subject_type', $subjectType)
            ->where('subject_uuid', $subjectUuid)
            ->orderBy('id')
            ->get()
            ->toBase();
    }

    /**
     * Supprime les entrées plus anciennes que la durée de conservation.
     *
     * @return int nombre d'entrées supprimées
     */
    public static function prune(): int
    {
        return AdminAuditLog::query()
            ->where('created_at', '<=', now()->subMonths((int) config('security.retention.admin_audit_months')))
            ->delete();
    }
}
