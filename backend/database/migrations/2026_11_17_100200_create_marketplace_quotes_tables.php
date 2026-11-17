<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Devis structurés (module Marketplace, décisions 36, 41 à 43, 47, 49 et 50).
     *
     * - Un devis appartient à une demande de mise en relation acceptée (match_request_id, clé
     *   étrangère en cascade : le devis disparaît avec sa demande, par exemple à la purge d'une
     *   conversation orpheline). provider_id et client_id passent à null à la suppression d'un
     *   compte.
     * - Montants en centimes (entiers), quantités en millièmes, taux en points de base : aucun
     *   nombre décimal. Les taux viennent des règles vérifiées du module Compliance et sont
     *   copiés dans chaque ligne à l'envoi (code, version, taux, libellé, mention).
     * - Contenu figé à l'envoi : issuer_snapshot (identité professionnelle), client_snapshot,
     *   lignes, totaux, et content_hash (SHA-256 du contenu) contrôlé à l'acceptation.
     * - Chiffrées par l'application (cast "encrypted", APP_KEY), en TEXT : identités de
     *   l'émetteur et du client, commentaire de refus.
     * - number : D-AAAA-NNNN, attribué au premier envoi ; une nouvelle version garde le numéro.
     * - Dates du cycle de vie facultatives : timestamp()->nullable() ou dateTime()->nullable().
     */
    public function up(): void
    {
        Schema::create('marketplace_quotes', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->string('number', 20)->nullable();
            $table->unsignedSmallInteger('version')->default(1);
            $table->foreignId('match_request_id')->constrained('match_requests')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revision_of_id')->nullable()->constrained('marketplace_quotes')->nullOnDelete();
            $table->string('client_kind', 16);
            $table->string('status', 16)->default('draft');
            $table->string('title', 160);
            $table->text('scope');
            $table->char('offer_uuid', 36)->nullable();
            $table->string('offer_title', 160)->nullable();
            $table->text('client_snapshot');
            $table->text('issuer_snapshot')->nullable();
            $table->char('currency', 3);
            $table->unsignedBigInteger('total_ht_cents')->default(0);
            $table->unsignedBigInteger('total_vat_cents')->default(0);
            $table->unsignedBigInteger('total_ttc_cents')->default(0);
            $table->json('vat_breakdown')->nullable();
            $table->unsignedSmallInteger('validity_days');
            $table->date('valid_until')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->date('start_date')->nullable();
            $table->string('duration', 200)->nullable();
            $table->string('payment_terms', 1000)->nullable();
            $table->unsignedBigInteger('deposit_cents')->nullable();
            $table->string('deposit_kind', 16)->nullable();
            $table->boolean('quote_is_free')->default(true);
            $table->unsignedBigInteger('quote_fee_cents')->nullable();
            $table->boolean('keep_replaced_parts')->nullable();
            $table->text('notes')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('accepted_at')->nullable();
            $table->string('acceptance_ip', 45)->nullable();
            $table->json('acceptance_data')->nullable();
            $table->dateTime('declined_at')->nullable();
            $table->string('decline_reason', 32)->nullable();
            $table->text('decline_comment')->nullable();
            $table->dateTime('withdrawn_at')->nullable();
            $table->dateTime('expired_at')->nullable();
            $table->dateTime('superseded_at')->nullable();
            $table->dateTime('withdrawal_exercised_at')->nullable();
            $table->dateTime('moderation_restricted_at')->nullable();
            $table->char('moderation_decision_uuid', 36)->nullable();
            $table->timestamps();

            $table->index(['match_request_id', 'status']);
            $table->index(['project_id', 'status']);
            $table->index(['provider_id', 'status']);
            $table->index(['client_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('marketplace_quote_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained('marketplace_quotes')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('line_type', 16);
            $table->string('designation', 300);
            $table->text('details')->nullable();
            $table->unsignedBigInteger('quantity_milli');
            $table->string('unit', 16);
            $table->unsignedBigInteger('unit_price_cents');
            $table->string('vat_rate_code', 100);
            $table->unsignedInteger('vat_rate_version')->nullable();
            $table->unsignedSmallInteger('vat_basis_points')->default(0);
            $table->json('vat_rate')->nullable();
            $table->unsignedBigInteger('total_ht_cents')->default(0);
            $table->timestamps();

            $table->index(['quote_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_quote_lines');
        Schema::dropIfExists('marketplace_quotes');
    }
};
