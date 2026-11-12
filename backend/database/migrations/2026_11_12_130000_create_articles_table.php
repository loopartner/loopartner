<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Articles du blog (module Blog).
     *
     * - uuid : identifiant pour l'auteur et les modérateurs ; le public ne voit que le slug, fixé
     *   à la première publication puis stable.
     * - user_id : auteur, nullable (article de l'équipe détaché d'un compte supprimé) ; les
     *   articles des autres auteurs sont supprimés avec leur compte (ForgetUserArticles).
     * - author_kind : editorial, provider ou entrepreneur, fixé à la création.
     * - body : document JSON de blocs, validé et reconstruit par le serveur (ArticleBody).
     * - Dates du cycle de vie : toutes facultatives, donc timestamp()->nullable() (aucune date
     *   obligatoire dans cette table).
     */
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->string('slug', 191)->nullable()->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_kind', 20);
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->char('language', 2);
            $table->string('title', 160);
            $table->string('excerpt', 300)->nullable();
            $table->json('body')->nullable();
            $table->string('author_name', 80)->nullable();
            $table->string('seo_title', 70)->nullable();
            $table->string('seo_description', 160)->nullable();
            $table->foreignId('cover_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->unsignedInteger('word_count')->default(0);
            $table->unsignedSmallInteger('reading_time_minutes')->default(1);
            $table->unsignedSmallInteger('submission_count')->default(0);
            $table->timestamp('first_submitted_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('content_updated_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('unpublished_at')->nullable();
            $table->text('unpublication_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'published_at']);
            $table->index(['user_id', 'status']);
            $table->index(['language', 'status', 'published_at']);
            $table->index(['status', 'submitted_at']);
            $table->index(['status', 'updated_at']);
        });

        Schema::create('article_category', function (Blueprint $table) {
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_category_id')->constrained()->restrictOnDelete();

            $table->primary(['article_id', 'service_category_id']);
            $table->index('service_category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_category');
        Schema::dropIfExists('articles');
    }
};
