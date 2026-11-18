<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Brique d'IA du noyau (lot 5, décisions 34, 62 et 65). Aucune table ne contient le contenu
     * envoyé à l'IA ni sa réponse.
     *
     * - ai_usages : un appel (fonctionnalité, fournisseur, modèle, jetons, coût réservé puis
     *   réel, statut). Détaché du compte à sa suppression (nullOnDelete), purgé après
     *   security.retention.ai_usages_months (ai:prune).
     * - ai_budgets : compteurs des plafonds de coût, une ligne par portée et par période
     *   (global:month, global:day, user:<id>), verrouillée pendant la réservation ; les lignes
     *   d'un utilisateur disparaissent avec lui (cascade).
     * - ai_usage_stats : totaux par jour, fonctionnalité et modèle, sans utilisateur.
     *
     * Montants en micro-unités de la devise du fournisseur (entiers).
     */
    public function up(): void
    {
        Schema::create('ai_usages', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('feature', 64);
            $table->string('provider', 32);
            $table->string('model', 64);
            $table->string('status', 16);
            $table->string('failure_reason', 32)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('reserved_micros')->default(0);
            $table->unsignedBigInteger('cost_micros')->default(0);
            $table->timestamps();

            $table->index('created_at');
        });

        Schema::create('ai_budgets', function (Blueprint $table) {
            $table->id();
            $table->string('scope_key', 64);
            $table->string('period', 10);
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('reserved_micros')->default(0);
            $table->unsignedBigInteger('spent_micros')->default(0);
            $table->unsignedTinyInteger('alerted_level')->default(0);
            $table->timestamps();

            $table->unique(['scope_key', 'period']);
        });

        Schema::create('ai_usage_stats', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('feature', 64);
            $table->string('model', 64);
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedInteger('failures')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cost_micros')->default(0);
            $table->timestamps();

            $table->unique(['day', 'feature', 'model'], 'ai_usage_stats_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_stats');
        Schema::dropIfExists('ai_budgets');
        Schema::dropIfExists('ai_usages');
    }
};
