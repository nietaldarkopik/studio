<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function index(Request $request, int $workspaceId)
    {
        $workspace = app(WorkspaceController::class)->authorizedWorkspace($request, $workspaceId);

        return response()->json([
            'data' => $workspace->projects()
                ->latest()
                ->limit(100)
                ->get(['id', 'client_id', 'title', 'topic', 'status', 'mode', 'created_at']),
        ]);
    }

    public function store(Request $request, int $workspaceId)
    {
        $workspace = app(WorkspaceController::class)->authorizedWorkspace($request, $workspaceId);
        abort_if($workspace->pivot->role === 'viewer', 403);

        $validated = $request->validate([
            'client_id' => ['nullable', 'uuid'],
            'title' => ['required', 'string', 'max:120'],
            'topic' => ['required', 'string', 'max:5000'],
            'mode' => ['sometimes', Rule::in(['manual', 'autonomous'])],
            'channel_id' => [
                'nullable',
                'integer',
                Rule::exists('channels', 'id')->where(
                    fn ($query) => $query->where('workspace_id', $workspace->id),
                ),
            ],
        ]);

        $attributes = [
            'workspace_id' => $workspace->id,
            'channel_id' => $validated['channel_id'] ?? null,
            'created_by' => $request->user()->id,
            'title' => trim($validated['title']),
            'topic' => trim($validated['topic']),
            'mode' => $validated['mode'] ?? 'manual',
            'status' => 'draft',
        ];

        [$project, $created] = DB::transaction(function () use ($validated, $attributes, $workspace, $request) {
            if (! empty($validated['client_id'])) {
                $project = Project::query()->firstOrCreate(
                    [
                        'workspace_id' => $workspace->id,
                        'client_id' => $validated['client_id'],
                    ],
                    $attributes,
                );
            } else {
                $project = Project::query()->create($attributes);
            }

            if ($project->wasRecentlyCreated) {
                AuditLog::query()->create([
                    'actor_id' => $request->user()->id,
                    'action' => 'project.created',
                    'subject_type' => 'project',
                    'subject_id' => $project->id,
                    'metadata' => ['workspace_id' => $workspace->id],
                ]);
            }

            return [$project, $project->wasRecentlyCreated];
        });

        return response()->json([
            'data' => $project->only(['id', 'client_id', 'title', 'topic', 'status', 'mode', 'created_at']),
        ], $created ? 201 : 200);
    }

    public function destroy(Request $request, int $projectId)
    {
        $project = Project::query()->findOrFail($projectId);
        $workspace = app(WorkspaceController::class)->authorizedWorkspace($request, $project->workspace_id);
        abort_if($workspace->pivot->role === 'viewer', 403);

        DB::transaction(function () use ($project, $workspace, $request): void {
            AuditLog::query()->create([
                'actor_id' => $request->user()->id,
                'action' => 'project.deleted',
                'subject_type' => 'project',
                'subject_id' => $project->id,
                'metadata' => ['workspace_id' => $workspace->id],
            ]);

            $project->delete();
        });

        return response()->json(['message' => 'Project deleted.']);
    }
}
