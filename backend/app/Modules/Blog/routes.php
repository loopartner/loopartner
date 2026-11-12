<?php

/*
| Routes du module Blog.
| Chargées par App\Providers\ModuleServiceProvider sous le préfixe /api/v1,
| avec les middlewares "api" et "throttle:api".
*/

use App\Http\Middleware\LimitRequestBody;
use App\Modules\Blog\Http\Controllers\OwnArticleController;
use Illuminate\Support\Facades\Route;

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
