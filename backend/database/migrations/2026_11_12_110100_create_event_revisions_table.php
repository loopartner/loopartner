<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Révisions d'un événement publié (module Events) : la nouvelle version proposée par
     * l'organisateur, appliquée seulement après la validation d'un admin.
     *
     * - payload : version complète proposée (tous les champs, catégories et opération sur la
     *   couverture), chiffrée par le modèle (elle contient le lien de connexion) : d'où text.
     * - changed_fields : noms des champs modifiés, en clair, sans contenu.
     * - pending_key vaut l'id de l'événement tant que la révision est en attente, null ensuite :
     *   son index unique garantit au plus une révision en attente par événement.
     * - version augmente à chaque remplacement de la révision en attente.
     * - decided_by : admin (sans clé étrangère, la trace survit à la suppression de son compte).
     */
    public function up(): void
    {
        Schema::create('event_revisions', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('version')->default(1);
            $table->text('payload');
            $table->json('changed_fields');
            $table->foreignId('cover_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->unsignedBigInteger('pending_key')->nullable()->unique();
            $table->timestamp('submitted_at');
            $table->timestamp('decided_at')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'status']);
            $table->index(['status', 'submitted_at']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_revisions');
    }
};
