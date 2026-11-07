<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateScriptDraft;
use App\Models\ScriptGeneration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ScriptGenerationController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const FORMATS = ['chain_build', 'mystery_investigation', 'flag_banter'];

    public function generate(Request $request, int $workspaceId, int $projectId): JsonResponse
    {
        $workspace = app(WorkspaceController::class)->authorizedWorkspace($request, $workspaceId);
        abort_if($workspace->pivot->role === 'viewer', 403);

        $project = $workspace->projects()->whereKey($projectId)->firstOrFail();
        $validated = $request->validate([
            'format' => ['required', Rule::in(self::FORMATS)],
        ]);

        $hasActiveGeneration = ScriptGeneration::query()
            ->where('project_id', $project->id)
            ->whereIn('status', ['queued', 'running'])
            ->exists();

        if ($hasActiveGeneration) {
            return response()->json([
                'message' => 'Masih ada draf AI yang sedang diproses di latar belakang.',
            ], 409);
        }

        $generation = ScriptGeneration::query()->create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'requested_by' => $request->user()->id,
            'format' => $validated['format'],
            'status' => 'queued',
        ]);

        GenerateScriptDraft::dispatch($generation->id);

        return response()->json([
            'generation' => $this->payload($generation),
        ], 202);
    }

    public function show(Request $request, int $workspaceId, int $projectId): JsonResponse
    {
        $workspace = app(WorkspaceController::class)->authorizedWorkspace($request, $workspaceId);
        $project = $workspace->projects()->whereKey($projectId)->firstOrFail();

        $generation = ScriptGeneration::query()
            ->where('project_id', $project->id)
            ->latest('id')
            ->first();

        return response()->json([
            'generation' => $generation === null ? null : $this->payload($generation),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ScriptGeneration $generation): array
    {
        $scenes = $generation->scenes ?? [];

        return [
            'id' => $generation->id,
            'status' => $generation->status,
            'format' => $generation->format,
            'model' => $generation->model,
            'narration' => $generation->narration,
            'scenes' => $scenes,
            'scene_count' => count($scenes),
            'storyboard_imported' => $generation->storyboard_imported_at !== null,
            'word_count' => $generation->word_count,
            'error_message' => $generation->error_message,
            'updated_at' => $generation->updated_at?->toIso8601String(),
        ];
    }
}
