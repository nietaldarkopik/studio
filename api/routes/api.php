<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProductionController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ResearchSourceController;
use App\Http\Controllers\Api\ScriptGenerationController;
use App\Http\Controllers\Api\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/workspaces/{workspaceId}', [WorkspaceController::class, 'show'])
        ->whereNumber('workspaceId');
    Route::get('/workspaces/{workspaceId}/projects', [ProjectController::class, 'index'])
        ->whereNumber('workspaceId');
    Route::post('/workspaces/{workspaceId}/projects', [ProjectController::class, 'store'])
        ->whereNumber('workspaceId');
    Route::get('/workspaces/{workspaceId}/projects/{projectId}/production', [ProductionController::class, 'show'])
        ->whereNumber('workspaceId')->whereNumber('projectId');
    Route::put('/workspaces/{workspaceId}/projects/{projectId}/script', [ProductionController::class, 'saveScript'])
        ->whereNumber('workspaceId')->whereNumber('projectId');
    Route::post('/workspaces/{workspaceId}/projects/{projectId}/script/generate', [ScriptGenerationController::class, 'generate'])
        ->whereNumber('workspaceId')->whereNumber('projectId')->middleware('throttle:5,1');
    Route::get('/workspaces/{workspaceId}/projects/{projectId}/script/generate', [ScriptGenerationController::class, 'show'])
        ->whereNumber('workspaceId')->whereNumber('projectId')->middleware('throttle:240,1');
    Route::post('/workspaces/{workspaceId}/projects/{projectId}/scenes', [ProductionController::class, 'createScene'])
        ->whereNumber('workspaceId')->whereNumber('projectId');
    Route::put('/workspaces/{workspaceId}/projects/{projectId}/scenes/order', [ProductionController::class, 'reorderScenes'])
        ->whereNumber('workspaceId')->whereNumber('projectId');
    Route::patch('/workspaces/{workspaceId}/projects/{projectId}/scenes/{sceneId}', [ProductionController::class, 'updateScene'])
        ->whereNumber('workspaceId')->whereNumber('projectId')->whereNumber('sceneId');
    Route::delete('/workspaces/{workspaceId}/projects/{projectId}/scenes/{sceneId}', [ProductionController::class, 'deleteScene'])
        ->whereNumber('workspaceId')->whereNumber('projectId')->whereNumber('sceneId');
    Route::post('/workspaces/{workspaceId}/projects/{projectId}/storyboard/import', [ProductionController::class, 'importStoryboard'])
        ->whereNumber('workspaceId')->whereNumber('projectId');
    Route::get('/workspaces/{workspaceId}/projects/{projectId}/research-sources', [ResearchSourceController::class, 'index'])
        ->whereNumber('workspaceId')->whereNumber('projectId');
    Route::post('/workspaces/{workspaceId}/projects/{projectId}/research-sources', [ResearchSourceController::class, 'store'])
        ->whereNumber('workspaceId')->whereNumber('projectId');
    Route::patch('/workspaces/{workspaceId}/projects/{projectId}/research-sources/{sourceId}', [ResearchSourceController::class, 'update'])
        ->whereNumber('workspaceId')->whereNumber('projectId')->whereNumber('sourceId');
    Route::delete('/workspaces/{workspaceId}/projects/{projectId}/research-sources/{sourceId}', [ResearchSourceController::class, 'destroy'])
        ->whereNumber('workspaceId')->whereNumber('projectId')->whereNumber('sourceId');
    Route::delete('/projects/{projectId}', [ProjectController::class, 'destroy'])
        ->whereNumber('projectId');
});
