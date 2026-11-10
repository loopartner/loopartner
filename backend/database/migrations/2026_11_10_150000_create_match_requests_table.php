<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Demandes de mise en relation (module Directory) : propositions d'un prestataire sur un
     * projet, et contacts d'un porteur de projet vers un prestataire.
     *
     * - uuid est le seul identifiant exposé par l'API.
     * - author_id, recipient_id et project_id passent à null à la suppression du compte ou du
     *   projet : une demande acceptée reste lisible par l'autre participant (voir ForgetUserMatching).
     * - active_key vaut "proposal:<auteur>:<projet>" ou "contact:<auteur>:<prestataire>" tant que la
     *   demande est en attente ou acceptée, null sinon. Son index unique garantit, même en cas
     *   d'envois simultanés, au plus une demande active par couple (plusieurs NULL sont admis par
     *   MySQL, MariaDB et SQLite).
     * - Montant estimé en unités entières de la devise (comme les budgets des projets).
     */
    public function up(): void
    {
        Schema::create('match_requests', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->string('type', 20);
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recipient_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->text('message');
            $table->unsignedInteger('price_amount')->nullable();
            $table->char('price_currency', 3)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('active_key', 191)->nullable()->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();

            $table->index(['recipient_id', 'status', 'created_at']);
            $table->index(['author_id', 'status', 'created_at']);
            $table->index(['status', 'expires_at']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_requests');
    }
};
