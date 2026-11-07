<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WorkflowController extends Controller
{
    private const STATUSES = ['draft', 'active', 'archived'];

    private const TRIGGERS = ['manual', 'schedule', 'webhook', 'event'];

    private const ACTIONS = ['start', 'approve', 'complete', 'fail', 'skip', 'reset'];

    public function __construct(private readonly WorkflowEngine $engine)
    {
    }

    public function index(Request $request, int $workspaceId, int $projectId): JsonResponse
    {
        [, $project] = $this->authorizedProject($request, $workspaceId, $projectId);

        return response()->json([
            'workflows' => $project->workflows()
                ->with('steps')
                ->get()
                ->map(fn (Workflow $workflow) => $this->payload($workflow))
                ->all(),
        ]);
    }

    public function store(Request $request, int $workspaceId, int $projectId): JsonResponse
    {
        [$workspace, $project] = $this->authorizedProject($request, $workspaceId, $projectId);
        $this->ensureCanEdit($workspace);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'trigger' => ['sometimes', Rule::in(self::TRIGGERS)],
            'steps' => ['sometimes', 'array', 'max:50'],
            'steps.*.key' => ['required', 'string', 'max:60', 'distinct'],
            'steps.*.name' => ['required', 'string', 'max:120'],
            'steps.*.type' => ['sometimes', 'string', 'max:24'],
            'steps.*.requires_approval' => ['sometimes', 'boolean'],
            'steps.*.depends_on' => ['sometimes', 'array'],
            'steps.*.depends_on.*' => ['string', 'max:60'],
            'steps.*.config' => ['sometimes', 'nullable', 'array'],
        ]);

        $workflow = DB::transaction(function () use ($request, $workspace, $project, $validated) {
            $workflow = $project->workflows()->create([
                'created_by' => $request->user()->id,
                'name' => trim($validated['name']),
                'status' => $validated['status'] ?? 'draft',
                'trigger' => $validated['trigger'] ?? 'manual',
            ]);

            foreach ($validated['steps'] ?? [] as $position => $step) {
                $workflow->steps()->create($this->stepAttributes($step, $position));
            }

            $this->engine->refresh($workflow);

            $this->audit($request, $workspace, $project, 'workflow.created', $workflow->id, [
                'name' => $workflow->name,
                'steps' => $workflow->steps()->count(),
            ]);

            return $workflow;
        });

        return response()->json(['workflow' => $this->payload($workflow->load('steps'))], 201);
    }

    public function show(Request $request, int $workspaceId, int $projectId, int $workflowId): JsonResponse
    {
        [, $project] = $this->authorizedProject($request, $workspaceId, $projectId);
        $workflow = $project->workflows()->with('steps')->whereKey($workflowId)->firstOrFail();

        return response()->json(['workflow' => $this->payload($workflow)]);
    }

    public function update(Request $request, int $workspaceId, int $projectId, int $workflowId): JsonResponse
    {
        [$workspace, $project] = $this->authorizedProject($request, $workspaceId, $projectId);
        $this->ensureCanEdit($workspace);
        $workflow = $project->workflows()->whereKey($workflowId)->firstOrFail();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'trigger' => ['sometimes', Rule::in(self::TRIGGERS)],
        ]);

        DB::transaction(function () use ($request, $workspace, $project, $workflow, $validated): void {
            $dirty = $workflow->fill($validated)->getDirty();
            unset($dirty['updated_at']);

            if ($dirty === []) {
                return;
            }

            if (isset($dirty['name'])) {
                $workflow->name = trim($workflow->name);
            }

            $workflow->save();

            $this->audit($request, $workspace, $project, 'workflow.updated', $workflow->id, [
                'fields' => array_keys($dirty),
            ]);
        });

        return response()->json(['workflow' => $this->payload($workflow->refresh()->load('steps'))]);
    }

    public function destroy(Request $request, int $workspaceId, int $projectId, int $workflowId): JsonResponse
    {
        [$workspace, $project] = $this->authorizedProject($request, $workspaceId, $projectId);
        $this->ensureCanEdit($workspace);
        $workflow = $project->workflows()->whereKey($workflowId)->firstOrFail();

        DB::transaction(function () use ($request, $workspace, $project, $workflow): void {
            $this->audit($request, $workspace, $project, 'workflow.removed', $workflow->id, [
                'name' => $workflow->name,
            ]);

            $workflow->delete();
        });

        return response()->json(['message' => 'Workflow deleted.']);
    }

    public function storeStep(Request $request, int $workspaceId, int $projectId, int $workflowId): JsonResponse
    {
        [$workspace, $project] = $this->authorizedProject($request, $workspaceId, $projectId);
        $this->ensureCanEdit($workspace);
        $workflow = $project->workflows()->whereKey($workflowId)->firstOrFail();

        $validated = $request->validate([
            'key' => [
                'required',
                'string',
                'max:60',
                Rule::unique('workflow_steps', 'key')->where(fn ($query) => $query->where('workflow_id', $workflow->id)),
            ],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['sometimes', 'string', 'max:24'],
            'requires_approval' => ['sometimes', 'boolean'],
            'depends_on' => ['sometimes', 'array'],
            'depends_on.*' => ['string', 'max:60'],
            'config' => ['sometimes', 'nullable', 'array'],
        ]);

        $this->guardDependencies($workflow, $validated['depends_on'] ?? []);

        $step = DB::transaction(function () use ($request, $workspace, $project, $workflow, $validated) {
            $step = $workflow->steps()->create($this->stepAttributes(
                $validated,
                (int) $workflow->steps()->max('position') + 1,
            ));

            $this->engine->refresh($workflow);

            $this->audit($request, $workspace, $project, 'workflow.step_added', $workflow->id, [
                'key' => $step->key,
            ]);

            return $step;
        });

        return response()->json(['step' => $this->stepPayload($step->refresh())], 201);
    }

    public function updateStep(Request $request, int $workspaceId, int $projectId, int $workflowId, int $stepId): JsonResponse
    {
        [$workspace, $project] = $this->authorizedProject($request, $workspaceId, $projectId);
        $this->ensureCanEdit($workspace);
        $workflow = $project->workflows()->whereKey($workflowId)->firstOrFail();
        $step = $workflow->steps()->whereKey($stepId)->firstOrFail();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'type' => ['sometimes', 'string', 'max:24'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'requires_approval' => ['sometimes', 'boolean'],
            'depends_on' => ['sometimes', 'array'],
            'depends_on.*' => ['string', 'max:60'],
            'config' => ['sometimes', 'nullable', 'array'],
        ]);

        if (array_key_exists('depends_on', $validated)) {
            $this->guardDependencies($workflow, $validated['depends_on'], $step->key);
        }

        DB::transaction(function () use ($request, $workspace, $project, $workflow, $step, $validated): void {
            $step->fill($validated);

            if (isset($validated['name'])) {
                $step->name = trim($step->name);
            }

            if ($step->isDirty()) {
                $step->save();
                $this->engine->refresh($workflow);

                $this->audit($request, $workspace, $project, 'workflow.step_updated', $workflow->id, [
                    'key' => $step->key,
                    'fields' => array_keys($step->getDirty()),
                ]);
            }
        });

        return response()->json(['step' => $this->stepPayload($step->refresh())]);
    }

    public function deleteStep(Request $request, int $workspaceId, int $projectId, int $workflowId, int $stepId): JsonResponse
    {
        [$workspace, $project] = $this->authorizedProject($request, $workspaceId, $projectId);
        $this->ensureCanEdit($workspace);
        $workflow = $project->workflows()->whereKey($workflowId)->firstOrFail();
        $step = $workflow->steps()->whereKey($stepId)->firstOrFail();

        DB::transaction(function () use ($request, $workspace, $project, $workflow, $step): void {
            $this->audit($request, $workspace, $project, 'workflow.step_removed', $workflow->id, [
                'key' => $step->key,
            ]);

            $step->delete();
            $this->engine->refresh($workflow);
        });

        return response()->json(['message' => 'Workflow step deleted.']);
    }

    public function transition(Request $request, int $workspaceId, int $projectId, int $workflowId, int $stepId): JsonResponse
    {
        [$workspace, $project] = $this->authorizedProject($request, $workspaceId, $projectId);
        $this->ensureCanEdit($workspace);
        $workflow = $project->workflows()->whereKey($workflowId)->firstOrFail();
        $step = $workflow->steps()->whereKey($stepId)->firstOrFail();

        $validated = $request->validate([
            'action' => ['required', Rule::in(self::ACTIONS)],
        ]);

        DB::transaction(function () use ($request, $workspace, $project, $workflow, $step, $validated): void {
            $this->engine->transition($step, $validated['action']);

            $this->audit($request, $workspace, $project, 'workflow.step_transition', $workflow->id, [
                'key' => $step->key,
                'action' => $validated['action'],
                'status' => $step->refresh()->status,
            ]);
        });

        return response()->json([
            'workflow' => $this->payload($workflow->refresh()->load('steps')),
        ]);
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function stepAttributes(array $step, int $position): array
    {
        return [
            'position' => $position,
            'key' => $step['key'],
            'name' => trim($step['name']),
            'type' => $step['type'] ?? 'custom',
            'status' => 'pending',
            'requires_approval' => $step['requires_approval'] ?? false,
            'depends_on' => $step['depends_on'] ?? [],
            'config' => $step['config'] ?? null,
        ];
    }

    /**
     * @param  array<int, string>  $dependencies
     */
    private function guardDependencies(Workflow $workflow, array $dependencies, ?string $selfKey = null): void
    {
        $known = $workflow->steps()->pluck('key')->all();

        foreach ($dependencies as $dependency) {
            abort_if($dependency === $selfKey, 422, 'Langkah tidak boleh bergantung pada dirinya sendiri.');
            abort_unless(in_array($dependency, $known, true), 422, 'Langkah dependensi tidak ditemukan pada workflow ini.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Workflow $workflow): array
    {
        $steps = $workflow->relationLoaded('steps')
            ? $workflow->steps
            : $workflow->steps()->get();

        return [
            'id' => $workflow->id,
            'name' => $workflow->name,
            'status' => $workflow->status,
            'trigger' => $workflow->trigger,
            'step_count' => $steps->count(),
            'completed_count' => $steps->where('status', 'completed')->count(),
            'steps' => $steps->map(fn (WorkflowStep $step) => $this->stepPayload($step))->all(),
            'updated_at' => $workflow->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stepPayload(WorkflowStep $step): array
    {
        return [
            'id' => $step->id,
            'position' => $step->position,
            'key' => $step->key,
            'name' => $step->name,
            'type' => $step->type,
            'status' => $step->status,
            'requires_approval' => $step->requires_approval,
            'approved' => $step->isApproved(),
            'depends_on' => $step->dependencies(),
            'config' => $step->config,
            'approved_at' => $step->approved_at,
            'started_at' => $step->started_at,
            'finished_at' => $step->finished_at,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(Request $request, $workspace, Project $project, string $action, int $workflowId, array $metadata): void
    {
        AuditLog::query()->create([
            'actor_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => 'workflow',
            'subject_id' => $workflowId,
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
