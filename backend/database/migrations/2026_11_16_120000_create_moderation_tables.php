<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Signalements, décisions motivées et recours (module Moderation, DSA art. 16, 17 et 20).
     *
     * - Un contenu est désigné par son type (liste fermée de config/moderation.php) et son
     *   identifiant public (target_ref : slug ou uuid), jamais par un id interne ni un nom de
     *   classe. Pas de clé étrangère vers le contenu : signalements et décisions survivent à
     *   sa suppression.
     * - target_owner_id / owner_id : sans clé étrangère, remis à null à la suppression du compte
     *   (ForgetUserModeration) : la décision reste, sans lien avec le compte.
     * - Les textes saisis (identité et email du signalant, explication, copie du contenu,
     *   faits, recours) sont chiffrés par l'application (colonnes text).
     * - active_key : empreinte (type, contenu, signalant) tant que le signalement est ouvert ;
     *   index unique, libéré à la clôture (un même signalant ne signale qu'une fois un contenu
     *   en cours d'examen).
     */
    public function up(): void
    {
        Schema::create('moderation_decisions', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->string('target_type', 32);
            $table->string('target_ref', 191);
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->text('target_label')->nullable();
            $table->string('measure', 30);
            $table->string('ground', 10)->nullable();
            $table->string('category', 40)->nullable();
            $table->string('legal_reference', 255)->nullable();
            $table->string('terms_clause', 64)->nullable();
            $table->text('facts');
            $table->text('correction_hint')->nullable();
            $table->string('source', 30);
            $table->boolean('automated')->default(false);
            $table->boolean('reversible')->default(true);
            $table->string('content_version', 64)->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->dateTime('decided_at');
            $table->dateTime('appeal_deadline_at')->nullable();
            $table->dateTime('lifted_at')->nullable();
            $table->unsignedBigInteger('lifted_by')->nullable();
            $table->text('lift_reason')->nullable();
            $table->timestamps();

            $table->index(['target_type', 'target_ref']);
            $table->index('owner_id');
            $table->index('decided_at');
        });

        Schema::create('moderation_notices', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->string('target_type', 32);
            $table->string('target_ref', 191);
            $table->unsignedBigInteger('target_owner_id')->nullable();
            $table->string('category', 40);
            $table->string('priority', 10);
            $table->string('source', 10);
            $table->string('source_reason', 30)->nullable();
            $table->text('explanation')->nullable();
            $table->text('location_detail')->nullable();
            $table->text('content_label')->nullable();
            $table->text('content_snapshot')->nullable();
            $table->string('content_version', 64)->nullable();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reporter_name')->nullable();
            $table->text('reporter_email')->nullable();
            $table->char('reporter_email_hash', 64)->nullable();
            $table->string('reporter_locale', 5)->nullable();
            $table->char('tracking_token_hash', 64)->nullable();
            $table->char('active_key', 64)->nullable()->unique();
            $table->dateTime('good_faith_confirmed_at')->nullable();
            $table->string('status', 20);
            $table->foreignId('decision_id')->nullable()->constrained('moderation_decisions')->nullOnDelete();
            $table->boolean('manifestly_unfounded')->default(false);
            $table->dateTime('due_at');
            $table->dateTime('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->dateTime('urgent_alert_sent_at')->nullable();
            $table->dateTime('referred_at')->nullable();
            $table->unsignedBigInteger('referred_by')->nullable();
            $table->dateTime('personal_data_purged_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'priority', 'due_at'], 'moderation_notices_queue_index');
            $table->index(['target_type', 'target_ref']);
            $table->index('target_owner_id');
            $table->index('reporter_email_hash');
            $table->index('closed_at');
        });

        Schema::create('moderation_appeals', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('decision_id')->constrained('moderation_decisions')->cascadeOnDelete();
            $table->foreignId('notice_id')->nullable()->constrained('moderation_notices')->nullOnDelete();
            $table->string('appellant_kind', 10);
            $table->foreignId('appellant_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('appellant_key', 64);
            $table->text('statement');
            $table->string('status', 10);
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->text('outcome_reason')->nullable();
            $table->timestamps();

            $table->index(['decision_id', 'appellant_key']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_appeals');
        Schema::dropIfExists('moderation_notices');
        Schema::dropIfExists('moderation_decisions');
    }
};
