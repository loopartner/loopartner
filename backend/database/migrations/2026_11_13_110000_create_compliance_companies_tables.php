<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Suivi administratif des entreprises (module Compliance) : entreprises, réglages,
     * échéances, parcours et tâches. Données strictement privées : seul leur propriétaire les
     * voit (404 pour tout autre utilisateur, admins compris). Tout est supprimé avec le compte.
     *
     * Colonnes chiffrées par l'application (cast "encrypted", APP_KEY), en TEXT : nom de
     * l'entreprise, numéro d'entreprise (SIRET…), numéro de TVA, titres et notes saisis par
     * l'utilisateur. Elles ne sont ni indexées ni utilisées dans une requête.
     *
     * - compliance_deadlines : échéances issues d'une règle (rule_code, occurrence_key : la
     *   génération est idempotente) ou personnelles (rule_code null). due_on est un jour, en DATE.
     * - compliance_tasks : rattachées à une échéance ou à un parcours (template_key : tâche
     *   issue de la règle), ou à l'entreprise seule (tâche libre).
     * - Dates du cycle de vie facultatives : timestamp()->nullable() ; started_at, obligatoire,
     *   en dateTime().
     */
    public function up(): void
    {
        Schema::create('compliance_companies', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('name');
            $table->char('country', 2);
            $table->string('legal_form_code', 100);
            $table->json('activity_types');
            $table->string('vat_regime', 32);
            $table->date('created_on');
            $table->char('fiscal_year_end', 5)->default('12-31');
            $table->json('options');
            $table->text('registration_number')->nullable();
            $table->text('vat_number')->nullable();
            $table->timestamp('calendar_refreshed_at')->nullable();
            $table->timestamp('calendar_changed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'id']);
            $table->index(['country', 'id']);
        });

        Schema::create('compliance_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('emails_enabled')->default(true);
            $table->string('reminder_frequency', 16)->default('daily');
            $table->json('reminder_offsets');
            $table->json('threshold_levels');
            $table->foreignId('active_company_id')->nullable()->constrained('compliance_companies')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('compliance_deadlines', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('company_id')->constrained('compliance_companies')->cascadeOnDelete();
            $table->string('source', 16);
            $table->string('rule_code', 100)->nullable();
            $table->string('occurrence_key', 32)->nullable();
            $table->foreignId('rule_id')->nullable()->constrained('compliance_rules')->nullOnDelete();
            $table->text('title')->nullable();
            $table->date('due_on');
            $table->string('status', 16)->default('todo');
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('obsolete_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'rule_code', 'occurrence_key']);
            $table->index(['company_id', 'due_on']);
            $table->index(['due_on', 'status']);
        });

        Schema::create('compliance_journeys', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('company_id')->constrained('compliance_companies')->cascadeOnDelete();
            $table->string('rule_code', 100);
            $table->foreignId('rule_id')->nullable()->constrained('compliance_rules')->nullOnDelete();
            $table->dateTime('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'completed_at']);
        });

        Schema::create('compliance_tasks', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('company_id')->constrained('compliance_companies')->cascadeOnDelete();
            $table->foreignId('deadline_id')->nullable()->constrained('compliance_deadlines')->cascadeOnDelete();
            $table->foreignId('journey_id')->nullable()->constrained('compliance_journeys')->cascadeOnDelete();
            $table->string('source', 16);
            $table->string('template_key', 50)->nullable();
            $table->text('title')->nullable();
            $table->string('service_category_slug', 100)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->date('due_on')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'id']);
            $table->index(['deadline_id', 'position']);
            $table->index(['journey_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_tasks');
        Schema::dropIfExists('compliance_journeys');
        Schema::dropIfExists('compliance_deadlines');
        Schema::dropIfExists('compliance_settings');
        Schema::dropIfExists('compliance_companies');
    }
};
