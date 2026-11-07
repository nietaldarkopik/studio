<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateAiDraft;
use App\Models\AiGeneration;
use App\Models\Project;
use App\Models\Scene;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AiGenerationController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const TASKS = ['prompt', 'prompt_all', 'research', 'research_search'];

    public function generate(Request $request, int $workspaceId, int $projectId, string $task): JsonResponse
    {
        $workspace = app(WorkspaceController::class)->authorizedWorkspace($request, $workspaceId);
        abort_if($workspace->pivot->role === 'viewer', 403);

        $project = $workspace->projects()->whereKey($projectId)->firstOrFail();
        abort_unless(in_array($task, self::TASKS, true), 404);

        $input = $this->validateInput($request, $task, $project);

        $hasActiveGeneration = AiGeneration::query()
            ->where('project_id', $project->id)
            ->where('task', $task)
            ->whereIn('status', ['queued', 'running'])
            ->exists();

        if ($hasActiveGeneration) {
            return response()->json([
                'message' => 'Masih ada draf AI yang sedang diproses di latar belakang.',
            ], 409);
        }

        $generation = AiGeneration::query()->create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'requested_by' => $request->user()->id,
            'task' => $task,
            'status' => 'queued',
            'input' => $input,
        ]);

        GenerateAiDraft::dispatch($generation->id);

        return response()->json([
            'generation' => $this->payload($generation),
        ], 202);
    }

    public function show(Request $request, int $workspaceId, int $projectId, string $task): JsonResponse
    {
        $workspace = app(WorkspaceController::class)->authorizedWorkspace($request, $workspaceId);
        $project = $workspace->projects()->whereKey($projectId)->firstOrFail();
        abort_unless(in_array($task, self::TASKS, true), 404);

        $generation = AiGeneration::query()
            ->where('project_id', $project->id)
            ->where('task', $task)
            ->latest('id')
            ->first();

        return response()->json([
            'generation' => $generation === null ? null : $this->payload($generation),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateInput(Request $request, string $task, Project $project): array
    {
        if ($task === 'prompt_all') {
            $withPrompt = $project->prompts()->whereNotNull('scene_id')->pluck('scene_id')->all();
            $scenes = $project->scenes()
                ->orderBy('position')
                ->get()
                ->reject(fn (Scene $scene) => in_array($scene->id, $withPrompt, true))
                ->values();

            abort_if($project->scenes()->doesntExist(), 409, 'Storyboard belum memiliki adegan.');
            abort_if($scenes->isEmpty(), 409, 'Semua adegan sudah memiliki prompt.');

            return [
                'project_title' => $project->title,
                'topic' => $project->topic,
                'scenes' => $scenes->map(fn (Scene $scene) => [
                    'scene_id' => $scene->id,
                    'title' => $scene->title,
                    'narration' => $scene->narration,
                    'visual_description' => $scene->visual_description,
                    'camera_direction' => $scene->camera_direction,
                ])->all(),
            ];
        }

        if ($task === 'prompt') {
            $validated = $request->validate([
                'scene_id' => [
                    'required',
                    'integer',
                    Rule::exists('scenes', 'id')->where(
                        fn ($query) => $query->where('project_id', $project->id),
                    ),
                ],
            ]);

            /** @var Scene $scene */
            $scene = $project->scenes()->whereKey($validated['scene_id'])->firstOrFail();

            return [
                'scene_id' => $scene->id,
                'project_title' => $project->title,
                'topic' => $project->topic,
                'scene_title' => $scene->title,
                'narration' => $scene->narration,
                'visual_description' => $scene->visual_description,
                'camera_direction' => $scene->camera_direction,
            ];
        }

        if ($task === 'research_search') {
            $validated = $request->validate([
                'query' => ['required', 'string', 'min:3', 'max:300'],
            ]);

            return [
                'query' => trim($validated['query']),
                'project_title' => $project->title,
                'topic' => $project->topic,
            ];
        }

        $validated = $request->validate([
            'url' => ['required', 'string', 'max:500', 'url'],
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'publisher' => ['sometimes', 'nullable', 'string', 'max:120'],
            'published_at' => ['sometimes', 'nullable', 'date'],
        ]);

        return [
            'url' => trim($validated['url']),
            'project_title' => $project->title,
            'topic' => $project->topic,
            'title' => $this->nullableText($validated['title'] ?? null),
            'publisher' => $this->nullableText($validated['publisher'] ?? null),
            'published_at' => $validated['published_at'] ?? null,
        ];
    }

    private function nullableText(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(AiGeneration $generation): array
    {
        return [
            'id' => $generation->id,
            'task' => $generation->task,
            'status' => $generation->status,
            'model' => $generation->model,
            'input' => $generation->input ?? [],
            'result' => $generation->result ?? [],
            'error_message' => $generation->error_message,
            'updated_at' => $generation->updated_at?->toIso8601String(),
        ];
    }
}
