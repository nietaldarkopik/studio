<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunProduction;
use App\Models\AuditLog;
use App\Models\ProductionRun;
use App\Services\Production\ProductionPipeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductionRunController extends Controller
{
    public function index(Request $request, int $workspaceId): JsonResponse
    {
        $workspace = app(WorkspaceController::class)->authorizedWorkspace($request, $workspaceId);

        return response()->json([
            'runs' => $workspace->productionRuns()->latest('id')->limit(20)->get()
                ->map(fn (ProductionRun $run) => $this->payload($run))->all(),
        ]);
    }

    public function show(Request $request, int $workspaceId, int $runId): JsonResponse
    {
        $workspace = app(WorkspaceController::class)->authorizedWorkspace($request, $workspaceId);
        $run = $workspace->productionRuns()->whereKey($runId)->firstOrFail();

        return response()->json(['run' => $this->payload($run)]);
    }

    public function store(Request $request, int $workspaceId): JsonResponse
    {
        $workspace = app(WorkspaceController::class)->authorizedWorkspace($request, $workspaceId);
        abort_if($workspace->pivot->role === 'viewer', 403);

        $validated = $request->validate([
            'theme' => ['sometimes', 'nullable', 'string', 'max:200'],
            'format' => ['sometimes', 'nullable', Rule::in(ProductionPipeline::FORMATS)],
            'channel_id' => ['sometimes', 'nullable', 'integer'],
            'project_id' => ['sometimes', 'nullable', 'integer'],
            'auto_publish' => ['sometimes', 'boolean'],
            'require_research' => ['sometimes', 'boolean'],
        ]);

        if (! empty($validated['channel_id'])) {
            abort_unless($workspace->channels()->whereKey($validated['channel_id'])->exists(), 422, 'Kanal tidak ditemukan.');
        }

        if (! empty($validated['project_id'])) {
            $existing = $workspace->projects()->whereKey($validated['project_id'])->first();
            abort_if($existing === null, 422, 'Proyek tidak ditemukan.');
            abort_unless(
                $existing->scenes()->whereNotNull('narration')->where('narration', '!=', '')->exists(),
                422,
                'Proyek ini belum punya storyboard dengan narasi per adegan. Isi dulu naskah dan storyboard.',
            );
        }

        abort_if(
            $workspace->productionRuns()->whereIn('status', ['queued', 'running'])->exists(),
            409,
            'Masih ada produksi otomatis yang berjalan di workspace ini.',
        );

        $run = $workspace->productionRuns()->create([
            'project_id' => $validated['project_id'] ?? null,
            'channel_id' => $validated['channel_id'] ?? null,
            'requested_by' => $request->user()->id,
            'status' => 'queued',
            'options' => array_filter([
                'theme' => $validated['theme'] ?? null,
                'format' => $validated['format'] ?? null,
                'auto_publish' => (bool) ($validated['auto_publish'] ?? config('services.production.auto_publish', false)),
                'existing_project' => ! empty($validated['project_id']) ? true : null,
                'require_research' => (bool) ($validated['require_research'] ?? config('services.production.require_research', false)),
            ], fn ($v) => $v !== null),
        ]);

        $this->audit($request, $workspace->id, 'production.started', $run);
        RunProduction::dispatch($run->id);

        return response()->json(['run' => $this->payload($run)], 202);
    }

    /** Lanjutkan dari tahap terakhir yang gagal / perlu ditinjau. */
    public function resume(Request $request, int $workspaceId, int $runId): JsonResponse
    {
        $run = $this->editableRun($request, $workspaceId, $runId);
        abort_unless(in_array($run->status, ['failed', 'needs_review'], true), 409, 'Produksi ini tidak sedang berhenti.');
        abort_if(
            $run->workspace->productionRuns()->whereKeyNot($run->id)->whereIn('status', ['queued', 'running'])->exists(),
            409,
            'Masih ada produksi otomatis lain yang berjalan.',
        );

        $run->forceFill(['status' => 'queued', 'error_message' => null])->save();
        $this->audit($request, $workspaceId, 'production.resumed', $run);
        RunProduction::dispatch($run->id);

        return response()->json(['run' => $this->payload($run->refresh())], 202);
    }

    /** Setujui upload ke YouTube untuk video yang sudah lolos QC. */
    public function approvePublish(Request $request, int $workspaceId, int $runId): JsonResponse
    {
        $run = $this->editableRun($request, $workspaceId, $runId);
        abort_unless($run->status === 'awaiting_approval', 409, 'Produksi ini tidak menunggu persetujuan upload.');
        abort_unless(($run->artifact('qc')['passed'] ?? false) === true, 409, 'Video belum lolos QC.');

        $options = $run->options ?? [];
        $options['publish_approved'] = true;
        $run->forceFill(['options' => $options, 'status' => 'queued'])->save();

        $this->audit($request, $workspaceId, 'production.publish_approved', $run);
        RunProduction::dispatch($run->id);

        return response()->json(['run' => $this->payload($run->refresh())], 202);
    }

    public function cancel(Request $request, int $workspaceId, int $runId): JsonResponse
    {
        $run = $this->editableRun($request, $workspaceId, $runId);
        abort_if(in_array($run->status, ['completed', 'cancelled'], true), 409, 'Produksi ini sudah selesai.');

        $run->forceFill(['status' => 'cancelled', 'finished_at' => now()])->save();
        $this->audit($request, $workspaceId, 'production.cancelled', $run);

        return response()->json(['run' => $this->payload($run->refresh())]);
    }

    private function editableRun(Request $request, int $workspaceId, int $runId): ProductionRun
    {
        $workspace = app(WorkspaceController::class)->authorizedWorkspace($request, $workspaceId);
        abort_if($workspace->pivot->role === 'viewer', 403);

        return $workspace->productionRuns()->whereKey($runId)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ProductionRun $run): array
    {
        $project = $run->project;
        $idea = $run->artifact('idea', []);

        return [
            'id' => $run->id,
            'status' => $run->status,
            'stage' => $run->stage,
            'error_message' => $run->error_message,
            'options' => $run->options ?? [],
            'project' => $project === null ? null : ['id' => $project->id, 'title' => $project->title],
            'theme' => $idea['theme'] ?? null,
            'title' => $idea['title'] ?? $project?->title,
            'stages' => collect(ProductionRun::STAGES)->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
            ] + $run->stageState($key))->values()->all(),
            'qc' => $run->artifact('qc'),
            'post_url' => $run->artifact('post_url'),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'updated_at' => $run->updated_at?->toIso8601String(),
        ];
    }

    private function audit(Request $request, int $workspaceId, string $action, ProductionRun $run): void
    {
        AuditLog::query()->create([
            'actor_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => 'production_run',
            'subject_id' => $run->id,
            'metadata' => ['workspace_id' => $workspaceId, 'project_id' => $run->project_id],
        ]);
    }
}
