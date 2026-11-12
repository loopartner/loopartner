<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Journal d'audit des décisions d'administration (noyau, App\Support\AdminAudit), utilisable
     * par tous les modules : qui a décidé quoi, sur quel élément, quand et avec quel motif.
     *
     * - admin_id n'a volontairement pas de clé étrangère : la trace de la décision survit à la
     *   suppression du compte de l'admin (les identifiants ne sont pas réutilisés).
     * - Les lignes ne sont jamais modifiées (App\Models\AdminAuditLog). Elles sont supprimées
     *   après security.retention.admin_audit_months (admin-audit:prune).
     */
    public function up(): void
    {
        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id');
            $table->string('action', 64);
            $table->string('subject_type', 32);
            $table->char('subject_uuid', 36);
            $table->unsignedInteger('version')->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['subject_type', 'subject_uuid']);
            $table->index(['admin_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
    }
};
