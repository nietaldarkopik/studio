<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Scene;
use App\Models\ScriptGeneration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductionController extends Controller
{
    public function show(Request $request, int $workspaceId, int $projectId): JsonResponse
    {
        [, $project] = $this->authorizedProject($request, $workspaceId, $projectId);
        $project->load(['script', 'scenes']);

        return response()->json([
            'project' => $project->only(['id', 'title', 'topic', 'status', 'mode']),
            'script' => $project->script?->only([
                'format',
                'narration',
                'word_count',
                'version',
                'status',
                'updated_at',
            ]),
            'scenes' => $project->scenes->map(fn (Scene $scene) => $this->scenePayload($scene)),
            'research' => app(ResearchSourceController::class)->summaryFor($project),
        ]);
    }

    public function saveScript(Request $request, int $workspaceId, int $projectId): JsonResponse
    {
        [$workspace, $project] = $this->authorizedProject($request, $workspaceId, $projectId);
        $this->ensureCanEdit($workspace);

        $validated = $request->validate([
            'format' => [
                'required',
                Rule::in(['chain_build', 'mystery_investigation', 'flag_banter']),
            ],
            'narration' => ['nullable', 'string'],
        ]);

        $narration = trim($validated['narration'] ?? '');
        preg_match_all('/\S+/u', $narration, $words);
        $wordCount = count($words[0]);

        $script = DB::transaction(function () use ($request, $workspace, $project, $validated, $narration, $wordCount) {
            $script = $project->script()->first();
            $isNew = $script === null;
            $changed = $isNew
                || $script->format !== $validated['format']
                || $script->narration !== $narration;

            if ($isNew) {
                $script = $project->script()->create([
                    'format' => $validated['format'],
                    'narration' => $narration,
                    'word_count' => $wordCount,
                    'version' => 1,
                    'status' => 'draft',
                ]);
            } elseif ($changed) {
                $script->fill([
                    'format' => $validated['format'],
                    'narration' => $narration,
                    'word_count' => $wordCount,
                    'version' => $script->version + 1,
                ])->save();
            }

            if ($changed) {
                AuditLog::query()->create([
                    'actor_id' => $request->user()->id,
                    'action' => 'script.saved',
                    'subject_type' => 'script',
                    'subject_id' => $script->id,
                    'metadata' => [
                        'workspace_id' => $workspace->id,
                        'project_id' => $project->id,
                        'version' => $script->version,
                    ],
                ]);
            }

            return $script;
        });

        return response()->json([
            'script' => $script->only([
                'format',
                'narration',
                'word_count',
                'version',
                'status',
                'updated_at',
            ]),
        ]);
    }

    public function createScene(Request $request, int $workspaceId, int $projectId): JsonResponse
    {
        [$workspace, $project] = $this->authorizedProject($request, $workspaceId, $projectId);
        $this->ensureCanEdit($workspace);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'duration_seconds' => ['sometimes', 'integer', 'between:1,60'],
        ]);

        $scene = DB::transaction(function () use ($request, $workspace, $project, $validated) {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $position = (int) $project->scenes()->max('position') + 1;
            $scene = $project->scenes()->create([
                'position' => $position,
                'title' => trim($validated['title']),
                'duration_seconds' => $validated['duration_seconds'] ?? 5,
                'status' => 'draft',
            ]);

            AuditLog::query()->create([
                'actor_id' => $request->user()->id,
                'action' => 'scene.created',
                'subject_type' => 'scene',
                'subject_id' => $scene->id,
                'metadata' => ['workspace_id' => $workspace->id, 'project_id' => $project->id],
            ]);

            return $scene;
        });

        return response()->json(['scene' => $this->scenePayload($scene)], 201);
    }

    public function updateScene(Request $request, int $workspaceId, int $projectId, int $sceneId): JsonResponse
    {
        [$workspace, $project] = $this->authorizedProject($request, $workspaceId, $projectId);
        $this->ensureCanEdit($workspace);
        $scene = $project->scenes()->whereKey($sceneId)->firstOrFail();

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:120'],
            'narration' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'visual_description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'camera_direction' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'duration_seconds' => ['sometimes', 'integer', 'between:1,60'],
        ]);

        foreach (['title', 'narration', 'visual_description', 'camera_direction'] as $field) {
            if (isset($validated[$field])) {
                $validated[$field] = trim($validated[$field]);
            }
        }

        DB::transaction(function () use ($request, $workspace, $project, $scene, $validated): void {
            $scene->fill($validated);
            if (! $scene->isDirty()) {
                return;
            }

            $scene->save();
            AuditLog::query()->create([
                'actor_id' => $request->user()->id,
                'action' => 'scene.updated',
                'subject_type' => 'scene',
                'subject_id' => $scene->id,
                'metadata' => ['workspace_id' => $workspace->id, 'project_id' => $project->id],
            ]);
        });

        return response()->json(['scene' => $this->scenePayload($scene->refresh())]);
    }

    public function reorderScenes(Request $request, int $workspaceId, int $projectId): JsonResponse
    {
        [$workspace, $project] = $this->authorizedProject($request, $workspaceId, $projectId);
        $this->ensureCanEdit($workspace);

        $validated = $request->validate([
            'scene_ids' => ['required', 'array', 'min:1'],
            'scene_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('scenes', 'id')->where(
                    fn ($query) => $query->where('project_id', $project->id),
                ),
            ],
        ]);

        $sceneIds = $validated['scene_ids'];
        abort_unless(count($sceneIds) === $project->scenes()->count(), 422, 'Include every scene exactly once.');

        DB::transaction(function () use ($request, $workspace, $project, $sceneIds): void {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $offset = count($sceneIds) + 1;
            Scene::query()->where('project_id', $project->id)->increment('position', $offset);

            foreach ($sceneIds as $position => $sceneId) {
                Scene::query()
                    ->where('project_id', $project->id)
                    ->whereKey($sceneId)
                    ->update(['position' => $position, 'updated_at' => now()]);
            }

            AuditLog::query()->create([
                'actor_id' => $request->user()->id,
                'action' => 'scene.order_changed',
                'subject_type' => 'project',
                'subject_id' => $project->id,
                'metadata' => ['workspace_id' => $workspace->id, 'scene_count' => count($sceneIds)],
            ]);
        });

        return response()->json([
            'scenes' => $project->scenes()->get()->map(fn (Scene $scene) => $this->scenePayload($scene)),
        ]);
    }

    public function deleteScene(Request $request, int $workspaceId, int $projectId, int $sceneId): JsonResponse
    {
        [$workspace, $project] = $this->authorizedProject($request, $workspaceId, $projectId);
        $this->ensureCanEdit($workspace);
        $scene = $project->scenes()->whereKey($sceneId)->firstOrFail();

        DB::transaction(function () use ($request, $workspace, $project, $scene): void {
            AuditLog::query()->create([
                'actor_id' => $request->user()->id,
                'action' => 'scene.deleted',
                'subject_type' => 'scene',
                'subject_id' => $scene->id,
                'metadata' => ['workspace_id' => $workspace->id, 'project_id' => $project->id],
            ]);

            $scene->delete();

            foreach ($project->scenes()->get() as $position => $remainingScene) {
                $remainingScene->position = $position;
                $remainingScene->save();
            }
        });

        return response()->json(['message' => 'Scene deleted.']);
    }

    public function importStoryboard(Request $request, int $workspaceId, int $projectId): JsonResponse
    {
        [$workspace, $project] = $this->authorizedProject($request, $workspaceId, $projectId);
        $this->ensureCanEdit($workspace);

        $validated = $request->validate([
            'generation_id' => ['required', 'integer'],
        ]);

        $generation = ScriptGeneration::query()
            ->where('project_id', $project->id)
            ->whereKey($validated['generation_id'])
            ->firstOrFail();

        abort_if($generation->status !== 'completed', 409, 'Draf AI belum selesai diproses.');
        abort_if($generation->storyboard_imported_at !== null, 409, 'Breakdown adegan draf ini sudah disimpan.');

        $draftScenes = $generation->scenes ?? [];
        abort_if($draftScenes === [], 409, 'Draf AI tidak memuat breakdown adegan.');

        DB::transaction(function () use ($request, $workspace, $project, $generation, $draftScenes): void {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $position = (int) $project->scenes()->max('position') + 1;

            foreach ($draftScenes as $draftScene) {
                $project->scenes()->create([
                    'position' => $position++,
                    'title' => $draftScene['title'],
                    'narration' => $draftScene['narration'] ?? null,
                    'visual_description' => $draftScene['visual_description'] ?? null,
                    'camera_direction' => $draftScene['camera_direction'] ?? null,
                    'duration_seconds' => $draftScene['duration_seconds'] ?? 5,
                    'status' => 'draft',
                ]);
            }

            $generation->forceFill(['storyboard_imported_at' => now()])->save();

            AuditLog::query()->create([
                'actor_id' => $request->user()->id,
                'action' => 'storyboard.imported',
                'subject_type' => 'project',
                'subject_id' => $project->id,
                'metadata' => [
                    'workspace_id' => $workspace->id,
                    'generation_id' => $generation->id,
                    'scene_count' => count($draftScenes),
                ],
            ]);
        });

        return response()->json([
            'scenes' => $project->scenes()->get()->map(fn (Scene $scene) => $this->scenePayload($scene)),
            'generation' => [
                'id' => $generation->id,
                'storyboard_imported' => true,
            ],
        ], 201);
    }

    private function authorizedProject(Request $request, int $workspaceId, int $projectId): array
    {
        $workspace = app(WorkspaceController::class)->authorizedWorkspace($request, $workspaceId);
        $project = $workspace->projects()->whereKey($projectId)->firstOrFail();

        return [$workspace, $project];
    }

    private function ensureCanEdit($workspace): void
    {
        abort_if($workspace->pivot->role === 'viewer', 403);
    }

    private function scenePayload(Scene $scene): array
    {
        return $scene->only([
            'id',
            'position',
            'title',
            'narration',
            'visual_description',
            'camera_direction',
            'duration_seconds',
            'status',
            'updated_at',
        ]);
    }
}
