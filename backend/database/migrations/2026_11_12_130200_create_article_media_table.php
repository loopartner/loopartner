<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Images dans le corps d'un article (usage article_image de la brique média).
     *
     * Chaque image est rattachée à un article, ou à une révision en attente qui la propose
     * (elle passe sur l'article à la validation). Les clés vers l'article et la révision sont en
     * restrict : un article ne se supprime que par l'Action DeleteArticle, qui supprime aussi
     * les fichiers. La suppression d'un média (MediaLibrary) retire sa ligne ici.
     */
    public function up(): void
    {
        Schema::create('article_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('article_revision_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('media_id')->unique()->constrained('media')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_media');
    }
};
