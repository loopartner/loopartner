<?php

/*
| Routes du module Blog.
| Chargées par App\Providers\ModuleServiceProvider sous le préfixe /api/v1,
| avec les middlewares "api" et "throttle:api".
*/

use App\Http\Middleware\EnsureModerator;
use App\Http\Middleware\LimitRequestBody;
use App\Modules\Blog\Http\Controllers\ModerationController;
use App\Modules\Blog\Http\Controllers\OwnArticleController;
use App\Modules\Blog\Http\Controllers\PublicArticleController;
use Illuminate\Support\Facades\Route;

// Slug public d'un article ou d'un prestataire.
$slug = '[a-z0-9]+(?:-[a-z0-9]+)*';

/*
| Lecture publique : uniquement les articles publiés. "sitemap" est déclaré avant {slug} (et
| aucun article ne reçoit ce slug, voir ArticlePublisher).
*/
Route::get('articles', [PublicArticleController::class, 'index'])->name('blog.articles.index');
Route::get('articles/sitemap', [PublicArticleController::class, 'sitemap'])->name('blog.articles.sitemap');
Route::get('articles/{slug}', [PublicArticleController::class, 'show'])->where('slug', $slug)->name('blog.articles.show');
Route::get('providers/{slug}/articles', [PublicArticleController::class, 'provider'])->where('slug', $slug)->name('blog.providers.articles');

// Taille maximale d'une requête d'écriture d'un article (413 au-delà, avant la validation).
$maxBody = LimitRequestBody::class.':'.((int) config('blog.body.max_request_kilobytes') * 1024);

/*
| Articles de l'auteur connecté (rédacteurs, admins, prestataires avec un profil publié,
| entrepreneurs), avec un email vérifié. L'article d'un autre utilisateur donne 404.
*/
Route::middleware(['auth:sanctum', 'verified'])
    ->prefix('me/articles')
    ->name('blog.own.')
    ->group(function () use ($maxBody): void {
        Route::get('/', [OwnArticleController::class, 'index'])->name('index');
        Route::get('quota', [OwnArticleController::class, 'quota'])->name('quota');
        Route::get('{uuid}', [OwnArticleController::class, 'show'])->whereUuid('uuid')->name('show');

        Route::middleware('throttle:article-writes')->group(function () use ($maxBody): void {
            Route::post('/', [OwnArticleController::class, 'store'])->middleware($maxBody)->name('store');
            Route::patch('{uuid}', [OwnArticleController::class, 'update'])->whereUuid('uuid')->middleware($maxBody)->name('update');
            Route::delete('{uuid}', [OwnArticleController::class, 'destroy'])->whereUuid('uuid')->name('destroy');
            Route::post('{uuid}/withdraw', [OwnArticleController::class, 'withdraw'])->whereUuid('uuid')->name('withdraw');
            Route::delete('{uuid}/revision', [OwnArticleController::class, 'withdrawRevision'])->whereUuid('uuid')->name('revision.destroy');
        });

        Route::post('{uuid}/submit', [OwnArticleController::class, 'submit'])
            ->whereUuid('uuid')
            ->middleware('throttle:article-submissions')
            ->name('submit');
    });

/*
| Modération éditoriale (API minimale, le back-office viendra plus tard) : rédacteurs et
| admins, avec la double authentification au niveau du compte et de la session
| (EnsureModerator). Tout autre rôle reçoit 404 ; sans 2FA, 403. Personne ne modère son propre
| article (403). Chaque décision est limitée (blog-moderation), journalisée (canal security)
| et tracée dans le journal d'audit.
*/
Route::middleware(['auth:sanctum', 'verified', EnsureModerator::class.':admin,redacteur'])
    ->prefix('admin')
    ->name('blog.moderation.')
    ->group(function (): void {
        Route::get('blog/queue', [ModerationController::class, 'queue'])->name('queue');
        Route::get('articles/{uuid}', [ModerationController::class, 'showArticle'])->whereUuid('uuid')->name('articles.show');
        Route::get('article-revisions/{uuid}', [ModerationController::class, 'showRevision'])->whereUuid('uuid')->name('revisions.show');

        Route::middleware('throttle:blog-moderation')->group(function (): void {
            Route::post('articles/{uuid}/approve', [ModerationController::class, 'approveArticle'])->whereUuid('uuid')->name('articles.approve');
            Route::post('articles/{uuid}/reject', [ModerationController::class, 'rejectArticle'])->whereUuid('uuid')->name('articles.reject');
            Route::post('articles/{uuid}/unpublish', [ModerationController::class, 'unpublishArticle'])->whereUuid('uuid')->name('articles.unpublish');
            Route::post('article-revisions/{uuid}/approve', [ModerationController::class, 'approveRevision'])->whereUuid('uuid')->name('revisions.approve');
            Route::post('article-revisions/{uuid}/reject', [ModerationController::class, 'rejectRevision'])->whereUuid('uuid')->name('revisions.reject');
        });
    });
