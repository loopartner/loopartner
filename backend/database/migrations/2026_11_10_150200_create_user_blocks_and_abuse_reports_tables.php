<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blocages entre utilisateurs et signalements (module Directory).
     *
     * - Un blocage vaut entre deux utilisateurs, dans toutes leurs conversations et pour toute
     *   nouvelle demande. Il disparaît avec l'un des deux comptes (cascade).
     * - Un signalement vise un message ou une demande (target_type : liste fermée, jamais un nom
     *   de classe). target_id n'a pas de clé étrangère : le signalement survit au contenu
     *   signalé, dont content_snapshot garde une copie pour la modération. Cette copie et le
     *   commentaire sont effacés après security.retention.abuse_reports_months, et les
     *   signalements clos supprimés.
     * - Un utilisateur ne signale qu'une fois le même contenu (index unique).
     */
    public function up(): void
    {
        Schema::create('user_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blocker_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['blocker_id', 'blocked_id']);
            $table->index('blocked_id');
        });

        Schema::create('abuse_reports', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reported_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('target_type', 20);
            $table->unsignedBigInteger('target_id');
            $table->string('reason', 30);
            $table->text('comment')->nullable();
            $table->text('content_snapshot')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['reporter_id', 'target_type', 'target_id']);
            $table->index(['status', 'created_at']);
            $table->index('closed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abuse_reports');
        Schema::dropIfExists('user_blocks');
    }
};
