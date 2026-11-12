<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Révisions d'un article publié (module Blog) : la nouvelle version proposée par un auteur
     * extérieur à l'équipe, appliquée seulement après la validation d'un modérateur.
     *
     * - payload : version complète proposée (champs, catégories, opération sur la couverture),
     *   en JSON non chiffré (un article ne contient aucun secret).
     * - pending_key vaut l'id de l'article tant que la révision est en attente, null ensuite :
     *   son index unique garantit au plus une révision en attente par article.
     * - submitted_at est obligatoire : dateTime (pas de ON UPDATE implicite sur MariaDB/MySQL).
     * - decided_by : modérateur, sans clé étrangère (la trace survit à la suppression de son compte).
     */
    public function up(): void
    {
        Schema::create('article_revisions', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('version')->default(1);
            $table->json('payload');
            $table->json('changed_fields');
            $table->foreignId('cover_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->unsignedBigInteger('pending_key')->nullable()->unique();
            $table->dateTime('submitted_at');
            $table->timestamp('decided_at')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['article_id', 'status']);
            $table->index(['status', 'submitted_at']);
            $table->index(['status', 'decided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_revisions');
    }
};
