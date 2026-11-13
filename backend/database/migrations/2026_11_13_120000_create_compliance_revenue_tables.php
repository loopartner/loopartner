<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chiffre d'affaires et alertes de seuil (module Compliance).
     *
     * - compliance_revenue_entries : un montant par mois (period = 1er jour du mois, en DATE) et
     *   par type d'activité. amount est un entier en centimes, CHIFFRÉ par l'application (TEXT) :
     *   jamais de somme en SQL, les calculs se font en PHP sur des entiers.
     * - compliance_threshold_alerts : palier atteint (kind = reached) ou dépassement projeté
     *   (kind = projected) d'un seuil, une seule fois par année : l'index unique empêche les
     *   doublons, notified_at marque l'envoi dans le récapitulatif par email.
     */
    public function up(): void
    {
        Schema::create('compliance_revenue_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('compliance_companies')->cascadeOnDelete();
            $table->date('period');
            $table->string('activity_type', 32);
            $table->text('amount');
            $table->timestamps();

            $table->unique(['company_id', 'period', 'activity_type']);
        });

        Schema::create('compliance_threshold_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('compliance_companies')->cascadeOnDelete();
            $table->string('rule_code', 100);
            $table->unsignedSmallInteger('year');
            $table->string('kind', 16);
            $table->unsignedSmallInteger('level');
            $table->dateTime('reached_at');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'rule_code', 'year', 'kind', 'level'], 'compliance_threshold_alerts_unique');
            $table->index(['notified_at', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_threshold_alerts');
        Schema::dropIfExists('compliance_revenue_entries');
    }
};
