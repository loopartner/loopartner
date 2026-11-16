<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Les signalements de la messagerie (Directory, table abuse_reports) rejoignent les
     * signalements de tous les contenus (module Moderation, table moderation_notices). Les
     * routes de signalement de Directory ne changent pas.
     *
     * Chaque ligne est recopiée : le message ou la demande est désigné par son uuid, le motif
     * d'origine est gardé (source_reason) et rattaché à une catégorie, le commentaire et la
     * copie du contenu sont chiffrés, un signalement clos devient « classé ». Aucune donnée
     * réelle n'existait à la date de cette migration (données de démonstration seulement).
     *
     * Les mêmes règles que l'application : SubmitNotice (empreinte active_key) et
     * Directory\Enums\ReportReason::category().
     */
    private const CATEGORIES = [
        'spam' => 'terms',
        'harassment' => 'hate_harassment',
        'scam' => 'scam_fraud',
        'inappropriate' => 'terms',
        'off_platform' => 'terms',
        'other' => 'other',
    ];

    private const STANDARD_HOURS = 168;

    public function up(): void
    {
        if (! Schema::hasTable('abuse_reports')) {
            return;
        }

        DB::table('abuse_reports')->orderBy('id')->chunkById(200, function ($reports): void {
            foreach ($reports as $report) {
                $table = $report->target_type === 'message' ? 'messages' : 'match_requests';
                $ref = DB::table($table)->where('id', $report->target_id)->value('uuid');
                $open = $report->status === 'open';
                $createdAt = Carbon::parse($report->created_at ?? 'now', 'UTC');
                $targetRef = is_string($ref) ? $ref : 'missing-'.$report->target_id;

                DB::table('moderation_notices')->insert([
                    'uuid' => $report->uuid,
                    'target_type' => $report->target_type,
                    'target_ref' => $targetRef,
                    'target_owner_id' => $report->reported_user_id,
                    'category' => self::CATEGORIES[$report->reason] ?? 'other',
                    'priority' => 'standard',
                    'source' => 'in_app',
                    'source_reason' => $report->reason,
                    'explanation' => $report->comment !== null ? Crypt::encryptString($report->comment) : null,
                    'content_snapshot' => $report->content_snapshot !== null ? Crypt::encryptString($report->content_snapshot) : null,
                    'reporter_id' => $report->reporter_id,
                    'active_key' => $open && $report->reporter_id !== null
                        ? hash('sha256', $report->target_type.'|'.$targetRef.'|user:'.$report->reporter_id)
                        : null,
                    'status' => $open ? 'open' : 'dismissed',
                    'due_at' => $createdAt->copy()->addHours(self::STANDARD_HOURS),
                    'closed_at' => $open ? null : ($report->closed_at ?? $report->updated_at),
                    'created_at' => $report->created_at,
                    'updated_at' => $report->updated_at,
                ]);
            }
        });

        Schema::drop('abuse_reports');
    }

    /**
     * Recrée la table d'origine, vide (les signalements restent dans moderation_notices).
     */
    public function down(): void
    {
        if (Schema::hasTable('abuse_reports')) {
            return;
        }

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
};
