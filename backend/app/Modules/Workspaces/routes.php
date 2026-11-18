<?php

/*
| Routes du module Workspaces (workspaces privés et copilote de projet, lot 5).
| Chargées par App\Providers\ModuleServiceProvider sous le préfixe /api/v1,
| avec les middlewares "api" et "throttle:api".
|
| Particuliers et entrepreneurs (WorkspacePolicy : 403 pour les autres rôles) ; email vérifié
| non requis pour le workspace (décision 63). Le workspace d'un autre utilisateur donne 404,
| admins compris. Aucune route publique : rien d'un workspace n'est visible d'autrui.
*/

use App\Http\Middleware\LimitRequestBody;
use App\Modules\Workspaces\Http\Controllers\ContentController;
use App\Modules\Workspaces\Http\Controllers\LinkedProjectController;
use App\Modules\Workspaces\Http\Controllers\SpecificationController;
use App\Modules\Workspaces\Http\Controllers\TemplateController;
use App\Modules\Workspaces\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('workspace-templates', TemplateController::class)->name('workspaces.templates');

    Route::prefix('me/workspaces')->name('workspaces.')->group(function (): void {
        Route::get('/', [WorkspaceController::class, 'index'])->name('index');
        Route::get('{uuid}', [WorkspaceController::class, 'show'])->whereUuid('uuid')->name('show');
        Route::get('{uuid}/specifications', [SpecificationController::class, 'index'])->whereUuid('uuid')->name('specifications.index');
        Route::get('{uuid}/specifications/{specification}', [SpecificationController::class, 'show'])->whereUuid(['uuid', 'specification'])->name('specifications.show');

        Route::middleware(['throttle:workspace-writes', LimitRequestBody::class.':65536'])->group(function (): void {
            Route::post('/', [WorkspaceController::class, 'store'])->name('store');
            Route::patch('{uuid}', [WorkspaceController::class, 'update'])->whereUuid('uuid')->name('update');
            Route::delete('{uuid}', [WorkspaceController::class, 'destroy'])->whereUuid('uuid')->name('destroy');
            Route::post('{uuid}/archive', [WorkspaceController::class, 'archive'])->whereUuid('uuid')->name('archive');
            Route::post('{uuid}/unarchive', [WorkspaceController::class, 'unarchive'])->whereUuid('uuid')->name('unarchive');
            Route::post('{uuid}/activate', [WorkspaceController::class, 'activate'])->whereUuid('uuid')->name('activate');

            Route::post('{uuid}/linked-project', [LinkedProjectController::class, 'store'])->whereUuid('uuid')->name('linked-project.store');
            Route::delete('{uuid}/linked-project', [LinkedProjectController::class, 'destroy'])->whereUuid('uuid')->name('linked-project.destroy');
            Route::post('{uuid}/directory-draft', [LinkedProjectController::class, 'draft'])->whereUuid('uuid')->name('directory-draft');

            Route::post('{uuid}/goals', [ContentController::class, 'storeGoal'])->whereUuid('uuid')->name('goals.store');
            Route::put('{uuid}/goals/order', [ContentController::class, 'reorderGoals'])->whereUuid('uuid')->name('goals.reorder');
            Route::patch('{uuid}/goals/{goal}', [ContentController::class, 'updateGoal'])->whereUuid(['uuid', 'goal'])->name('goals.update');
            Route::delete('{uuid}/goals/{goal}', [ContentController::class, 'destroyGoal'])->whereUuid(['uuid', 'goal'])->name('goals.destroy');

            Route::post('{uuid}/steps', [ContentController::class, 'storeStep'])->whereUuid('uuid')->name('steps.store');
            Route::put('{uuid}/steps/order', [ContentController::class, 'reorderSteps'])->whereUuid('uuid')->name('steps.reorder');
            Route::patch('{uuid}/steps/{step}', [ContentController::class, 'updateStep'])->whereUuid(['uuid', 'step'])->name('steps.update');
            Route::delete('{uuid}/steps/{step}', [ContentController::class, 'destroyStep'])->whereUuid(['uuid', 'step'])->name('steps.destroy');
            Route::post('{uuid}/steps/{step}/tasks', [ContentController::class, 'storeTask'])->whereUuid(['uuid', 'step'])->name('tasks.store');
            Route::put('{uuid}/steps/{step}/tasks/order', [ContentController::class, 'reorderTasks'])->whereUuid(['uuid', 'step'])->name('tasks.reorder');
            Route::patch('{uuid}/tasks/{task}', [ContentController::class, 'updateTask'])->whereUuid(['uuid', 'task'])->name('tasks.update');
            Route::delete('{uuid}/tasks/{task}', [ContentController::class, 'destroyTask'])->whereUuid(['uuid', 'task'])->name('tasks.destroy');

            Route::post('{uuid}/specifications', [SpecificationController::class, 'store'])->whereUuid('uuid')->name('specifications.store');
            Route::put('{uuid}/specifications/{specification}', [SpecificationController::class, 'update'])->whereUuid(['uuid', 'specification'])->name('specifications.update');
            Route::delete('{uuid}/specifications/{specification}', [SpecificationController::class, 'destroy'])->whereUuid(['uuid', 'specification'])->name('specifications.destroy');
        });
    });
});
