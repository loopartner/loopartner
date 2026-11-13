<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Règles fiscales et sociales (module Compliance) : statuts juridiques, échéances, seuils,
     * taux et parcours, par pays. Aucune de ces valeurs n'est écrite dans le code.
     *
     * - code : clé stable d'une règle, partagée par ses versions successives et ses années
     *   (« fr.vat.franchise.services »). Les échéances des utilisateurs y sont rattachées.
     * - status : draft (jamais montrée aux utilisateurs), verified, retired.
     * - version : augmente à chaque modification ; l'admin envoie la version lue (409 sinon).
     * - replaces_rule_id : brouillon qui remplacera, une fois vérifié, une règle vérifiée.
     * - title, summary, conditions, payload : JSON reconstruit par le serveur (liste fermée).
     * - created_by, updated_by, verified_by : admins, sans clé étrangère (comme admin_audit_logs) ;
     *   jamais exposés.
     * - valid_from, valid_until : dates (pas d'heure), en DATE.
     */
    public function up(): void
    {
        Schema::create('compliance_rules', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->char('country', 2);
            $table->string('kind', 32);
            $table->string('code', 100);
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->json('title');
            $table->json('summary')->nullable();
            $table->json('conditions');
            $table->json('payload');
            $table->string('source_url', 500)->nullable();
            $table->string('source_label', 200)->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->text('verification_note')->nullable();
            $table->text('internal_notes')->nullable();
            $table->foreignId('replaces_rule_id')->nullable()->constrained('compliance_rules')->nullOnDelete();
            $table->boolean('is_demo')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->text('retirement_reason')->nullable();
            $table->timestamps();

            $table->index(['country', 'code']);
            $table->index(['country', 'kind', 'status']);
            $table->index(['status', 'valid_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_rules');
    }
};
