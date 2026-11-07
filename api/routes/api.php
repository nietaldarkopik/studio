<?php

use App\Http\Controllers\Api\AiGenerationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProductionController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\PromptController;
use App\Http\Controllers\Api\ResearchSourceController;
use App\Http\Controllers\Api\ScriptGenerationController;
use App\Http\Controllers\Api\WorkflowController;
use App\Http\Controllers\Api\WorkspaceController;
use App\Http\Controllers\Api\WorkspaceSettingsController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/workspaces/{workspaceId}', [WorkspaceController::class, 'show'])
        ->whereNumber('workspaceId');
    Route::patch('/workspaces/{workspaceId}', [WorkspaceSettingsController::class, 'update'])
        ->whereNumber('workspaceId');
    Route::get('/workspaces/{workspaceId}/channels', [WorkspaceSettingsController::class, 'channels'])
        ->whereNumber('workspaceId');
    Route::post('/workspaces/{workspaceId}/channels', [WorkspaceSettingsController::class, 'storeChannel'])
        ->whereNumber('workspaceId');
    Route::patch('/workspaces/{workspaceId}/channels/{channelId}', [WorkspaceSettingsController::class, 'updateChannel'])
        ->whereNumber('workspaceId')->whereNumber('channelId');
    Route::delete('/workspaces/{workspaceId}/channels/{channelId}', [WorkspaceSettingsController::class, 'deleteChannel'])
        ->whereNumber('workspaceId')->whereNumber('channelId');
    Route::get('/workspaces/{workspaceId}/members', [WorkspaceSettingsController::class, 'members'])
        ->whereNumber('workspaceId');
    Route::post('/workspaces/{workspaceId}/members', [WorkspaceSettingsController::class, 'storeMember'])
        ->whereNumber('workspaceId');
    Route::patch('/workspaces/{workspaceId}/members/{userId}', [WorkspaceSettingsController::class, 'updateMember'])
        ->whereNumber('workspaceId')->whereNumber('userId');
    Route::delete('/workspaces/{workspaceId}/members/{userId}', [WorkspaceSettingsController::class, 'deleteMember'])
        ->whereNumber('workspaceId')->whereNumber('userId');
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
    Route::post('/workspaces/{workspaceId}/projects/{projectId}/ai/{task}', [AiGenerationController::class, 'generate'])
        ->whereNumber('workspaceId')->whereNumber('projectId')
        ->whereIn('task', ['prompt', 'prompt_all', 'research', 'research_search'])->middleware('throttle:10,1');
    Route::get('/workspaces/{workspaceId}/projects/{projectId}/ai/{task}', [AiGenerationController::class, 'show'])
        ->whereNumber('workspaceId')->whereNumber('projectId')
        ->whereIn('task', ['prompt', 'prompt_all', 'research', 'research_search'])->middleware('throttle:240,1');
    Route::get('/workspaces/{workspaceId}/projects/{projectId}/prompts', [PromptController::class, 'index'])
        ->whereNumber('workspaceId')->whereNumber('projectId');
    Route::post('/workspaces/{workspaceId}/projects/{projectId}/prompts', [PromptController::class, 'store'])
        ->whereNumber('workspaceId')->whereNumber('projectId');
    Route::get('/workspaces/{workspaceId}/projects/{projectId}/prompts/{promptId}/revisions', [PromptController::class, 'revisions'])
        ->whereNumber('workspaceId')->whereNumber('projectId')->whereNumber('promptId');
    Route::patch('/workspaces/{workspaceId}/projects/{projectId}/prompts/{promptId}', [PromptController::class, 'update'])
        ->whereNumber('workspaceId')->whereNumber('projectId')->whereNumber('promptId');
    Route::delete('/workspaces/{workspaceId}/projects/{projectId}/prompts/{promptId}', [PromptController::class, 'destroy'])
        ->whereNumber('workspaceId')->whereNumber('projectId')->whereNumber('promptId');
    Route::get('/workspaces/{workspaceId}/projects/{projectId}/workflows', [WorkflowController::class, 'index'])
        ->whereNumber('workspaceId')->whereNumber('projectId');
    Route::post('/workspaces/{workspaceId}/projects/{projectId}/workflows', [WorkflowController::class, 'store'])
        ->whereNumber('workspaceId')->whereNumber('projectId');
    Route::get('/workspaces/{workspaceId}/projects/{projectId}/workflows/{workflowId}', [WorkflowController::class, 'show'])
        ->whereNumber('workspaceId')->whereNumber('projectId')->whereNumber('workflowId');
    Route::patch('/workspaces/{workspaceId}/projects/{projectId}/workflows/{workflowId}', [WorkflowController::class, 'update'])
        ->whereNumber('workspaceId')->whereNumber('projectId')->whereNumber('workflowId');
    Route::delete('/workspaces/{workspaceId}/projects/{projectId}/workflows/{workflowId}', [WorkflowController::class, 'destroy'])
        ->whereNumber('workspaceId')->whereNumber('projectId')->whereNumber('workflowId');
    Route::post('/workspaces/{workspaceId}/projects/{projectId}/workflows/{workflowId}/steps', [WorkflowController::class, 'storeStep'])
        ->whereNumber('workspaceId')->whereNumber('projectId')->whereNumber('workflowId');
    Route::patch('/workspaces/{workspaceId}/projects/{projectId}/workflows/{workflowId}/steps/{stepId}', [WorkflowController::class, 'updateStep'])
        ->whereNumber('workspaceId')->whereNumber('projectId')->whereNumber('workflowId')->whereNumber('stepId');
    Route::delete('/workspaces/{workspaceId}/projects/{projectId}/workflows/{workflowId}/steps/{stepId}', [WorkflowController::class, 'deleteStep'])
        ->whereNumber('workspaceId')->whereNumber('projectId')->whereNumber('workflowId')->whereNumber('stepId');
    Route::post('/workspaces/{workspaceId}/projects/{projectId}/workflows/{workflowId}/steps/{stepId}/transition', [WorkflowController::class, 'transition'])
        ->whereNumber('workspaceId')->whereNumber('projectId')->whereNumber('workflowId')->whereNumber('stepId');
    Route::delete('/projects/{projectId}', [ProjectController::class, 'destroy'])
        ->whereNumber('projectId');
});
