<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Prompt;
use App\Models\PromptRevision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PromptController extends Controller
{
    /**
     * Structured prompt fields that make up a revision snapshot.
     */
    private const CONTENT_FIELDS = [
        'title',
        'positive',
        'negative',
        'style',
        'camera',
        'lighting',
        'mood',
        'notes',
    ];

    public function index(Request $request, int $workspaceId, int $projectId): JsonResponse
    {
        [, $project] = $this->authorizedProject($request, $workspaceId, $projectId);

        return response()->json(['prompts' => $this->listPrompts($project)]);
    }

    public function store(Request $request, int $workspaceId, int $projectId): JsonResponse
    {
        [$workspace, $project] = $this->authorizedProject($request, $workspaceId, $projectId);
        $this->ensureCanEdit($workspace);

        $validated = $this->validatePrompt($request, $project, true);
        abort_if(
            $project->prompts()->where('scene_id', $validated['scene_id'])->exists(),
            409,
            'Adegan ini sudah memiliki prompt terstruktur.',
        );

        $prompt = DB::transaction(function () use ($request, $workspace, $project, $validated) {
            $prompt = $project->prompts()->create($validated + [
                'created_by' => $request->user()->id,
                'status' => $validated['status'] ?? 'draft',
                'version' => 1,
            ]);

            $this->recordRevision($prompt, $request->user()->id);

            $this->audit($request, $workspace, $project, 'prompt.created', $prompt->id, [
                'version' => $prompt->version,
            ]);

            return $prompt;
        });

        return response()->json(['prompt' => $this->payload($prompt) ], 201);
    }

    public function update(Request $request, int $workspaceId, int $projectId, int $promptId): JsonResponse
    {
        [$workspace, $project] = $this->authorizedProject($request, $workspaceId, $projectId);
        $this->ensureCanEdit($workspace);
        $prompt = $project->prompts()->whereKey($promptId)->firstOrFail();

        $validated = $this->validatePrompt($request, $project, false);

        DB::transaction(function () use ($request, $workspace, $project, $prompt, $validated): void {
            $dirty = $prompt->fill($validated)->getDirty();
            unset($dirty['updated_at']);

            if ($dirty === []) {
                return;
            }

            $contentChanged = (bool) array_intersect_key($dirty, array_flip(self::CONTENT_FIELDS));
            if ($contentChanged) {
                $prompt->version = $prompt->version + 1;
            }

            $prompt->save();

            if ($contentChanged) {
                $this->recordRevision($prompt, $request->user()->id);
            }

            $this->audit($request, $workspace, $project, 'prompt.updated', $prompt->id, [
                'version' => $prompt->version,
                'status' => $prompt->status,
            ]);
        });

        return response()->json(['prompt' => $this->payload($prompt->refresh())]);
    }

    public function destroy(Request $request, int $workspaceId, int $projectId, int $promptId): JsonResponse
    {
        [$workspace, $project] = $this->authorizedProject($request, $workspaceId, $projectId);
        $this->ensureCanEdit($workspace);
        $prompt = $project->prompts()->whereKey($promptId)->firstOrFail();

        DB::transaction(function () use ($request, $workspace, $project, $prompt): void {
            $this->audit($request, $workspace, $project, 'prompt.removed', $prompt->id, [
                'version' => $prompt->version,
            ]);

            $prompt->delete();
        });

        return response()->json(['message' => 'Prompt deleted.']);
    }

    public function revisions(Request $request, int $workspaceId, int $projectId, int $promptId): JsonResponse
    {
        [, $project] = $this->authorizedProject($request, $workspaceId, $projectId);
        $prompt = $project->prompts()->whereKey($promptId)->firstOrFail();

        return response()->json([
            'revisions' => $prompt->revisions()->get()->map(fn (PromptRevision $revision) => [
                'version' => $revision->version,
                'snapshot' => $revision->snapshot,
                'created_at' => $revision->created_at,
            ])->all(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listPrompts(Project $project): array
    {
        $scenes = $project->scenes()->get(['id', 'position', 'title']);
        $position = $scenes->pluck('position', 'id');
        $titles = $scenes->pluck('title', 'id');

        return $project->prompts()
            ->withCount('revisions')
            ->get()
            ->sortBy(fn (Prompt $prompt) => $prompt->scene_id === null
                ? PHP_INT_MAX
                : (int) ($position[$prompt->scene_id] ?? PHP_INT_MAX))
            ->values()
            ->map(fn (Prompt $prompt) => $this->payload($prompt, $titles))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePrompt(Request $request, Project $project, bool $creating): array
    {
        $presence = $creating ? 'required' : 'sometimes';

        $rules = [
            'title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'positive' => [$presence, 'string', 'max:4000'],
            'negative' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'style' => ['sometimes', 'nullable', 'string', 'max:160'],
            'camera' => ['sometimes', 'nullable', 'string', 'max:160'],
            'lighting' => ['sometimes', 'nullable', 'string', 'max:160'],
            'mood' => ['sometimes', 'nullable', 'string', 'max:120'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in(['draft', 'approved'])],
        ];

        if ($creating) {
            $rules['scene_id'] = [
                'required',
                'integer',
                Rule::exists('scenes', 'id')->where(
                    fn ($query) => $query->where('project_id', $project->id),
                ),
            ];
        }

        $validated = $request->validate($rules);

        foreach (self::CONTENT_FIELDS as $field) {
            if (array_key_exists($field, $validated) && is_string($validated[$field])) {
                $validated[$field] = trim($validated[$field]);
            }
        }

        return $validated;
    }

    private function recordRevision(Prompt $prompt, int $userId): void
    {
        $prompt->revisions()->create([
            'created_by' => $userId,
            'version' => $prompt->version,
            'snapshot' => $this->snapshot($prompt),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Prompt $prompt): array
    {
        return array_intersect_key($prompt->only(self::CONTENT_FIELDS), array_flip(self::CONTENT_FIELDS));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, string>|null  $sceneTitles
     * @return array<string, mixed>
     */
    private function payload(Prompt $prompt, $sceneTitles = null): array
    {
        return [
            'id' => $prompt->id,
            'scene_id' => $prompt->scene_id,
            'scene_title' => $sceneTitles?->get($prompt->scene_id),
            'title' => $prompt->title,
            'positive' => $prompt->positive,
            'negative' => $prompt->negative,
            'style' => $prompt->style,
            'camera' => $prompt->camera,
            'lighting' => $prompt->lighting,
            'mood' => $prompt->mood,
            'notes' => $prompt->notes,
            'status' => $prompt->status,
            'version' => $prompt->version,
            'revision_count' => $prompt->revisions_count ?? $prompt->revisions()->count(),
            'updated_at' => $prompt->updated_at,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(Request $request, $workspace, Project $project, string $action, int $promptId, array $metadata): void
    {
        AuditLog::query()->create([
            'actor_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => 'prompt',
            'subject_id' => $promptId,
            'metadata' => $metadata + [
                'workspace_id' => $workspace->id,
                'project_id' => $project->id,
            ],
        ]);
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
}
