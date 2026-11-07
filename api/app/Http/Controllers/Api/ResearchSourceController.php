<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ResearchSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ResearchSourceController extends Controller
{
    public function index(Request $request, int $workspaceId, int $projectId): JsonResponse
    {
        [, $project] = $this->authorizedProject($request, $workspaceId, $projectId);

        return response()->json(['research' => $this->summaryFor($project)]);
    }

    public function store(Request $request, int $workspaceId, int $projectId): JsonResponse
    {
        [$workspace, $project] = $this->authorizedProject($request, $workspaceId, $projectId);
        $this->ensureCanEdit($workspace);

        $validated = $this->validateSource($request, true);
        $source = $project->researchSources()->create($validated + [
            'created_by' => $request->user()->id,
            'verified' => false,
        ]);

        $this->audit($request, $workspace, $project, 'research.source_added', $source->id, [
            'title' => $source->title,
        ]);

        return response()->json(['research' => $this->summaryFor($project)], 201);
    }

    public function update(Request $request, int $workspaceId, int $projectId, int $sourceId): JsonResponse
    {
        [$workspace, $project] = $this->authorizedProject($request, $workspaceId, $projectId);
        $this->ensureCanEdit($workspace);
        $source = $project->researchSources()->whereKey($sourceId)->firstOrFail();

        $validated = $this->validateSource($request, false);
        if ($request->has('verified')) {
            $validated['verified'] = $request->validate(['verified' => ['required', 'boolean']])['verified'];
        }

        $source->fill($validated);
        if ($source->isDirty()) {
            $source->save();
            $this->audit($request, $workspace, $project, 'research.source_updated', $source->id, [
                'title' => $source->title,
                'verified' => (bool) $source->verified,
            ]);
        }

        return response()->json(['research' => $this->summaryFor($project)]);
    }

    public function destroy(Request $request, int $workspaceId, int $projectId, int $sourceId): JsonResponse
    {
        [$workspace, $project] = $this->authorizedProject($request, $workspaceId, $projectId);
        $this->ensureCanEdit($workspace);
        $source = $project->researchSources()->whereKey($sourceId)->firstOrFail();

        $this->audit($request, $workspace, $project, 'research.source_removed', $source->id, [
            'title' => $source->title,
        ]);
        $source->delete();

        return response()->json(['research' => $this->summaryFor($project)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function summaryFor(Project $project): array
    {
        $sources = $project->researchSources()->get();
        $verified = $sources->where('verified', true);
        $domains = $this->independentDomains($verified);

        return [
            'sources' => $sources->map(fn (ResearchSource $source) => [
                'id' => $source->id,
                'title' => $source->title,
                'url' => $source->url,
                'publisher' => $source->publisher,
                'published_at' => $source->published_at?->toDateString(),
                'key_findings' => $source->key_findings,
                'reliability_note' => $source->reliability_note,
                'verified' => (bool) $source->verified,
            ])->all(),
            'source_count' => $sources->count(),
            'verified_count' => $verified->count(),
            'independent_domain_count' => $domains->count(),
            'verified' => $verified->count() >= 2 && $domains->count() >= 2,
        ];
    }

    /**
     * @param  Collection<int, ResearchSource>  $sources
     * @return Collection<int, string>
     */
    private function independentDomains(Collection $sources): Collection
    {
        return $sources
            ->map(function (ResearchSource $source): string {
                $host = parse_url($source->url, PHP_URL_HOST);

                return is_string($host) ? strtolower((string) preg_replace('/^www\./', '', $host)) : '';
            })
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateSource(Request $request, bool $creating): array
    {
        $presence = $creating ? 'required' : 'sometimes';

        $validated = $request->validate([
            'title' => [$presence, 'string', 'max:200'],
            'url' => [$presence, 'string', 'max:500', 'url'],
            'publisher' => ['sometimes', 'nullable', 'string', 'max:120'],
            'published_at' => ['sometimes', 'nullable', 'date'],
            'key_findings' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'reliability_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        foreach (['title', 'url', 'publisher', 'key_findings', 'reliability_note'] as $field) {
            if (array_key_exists($field, $validated) && is_string($validated[$field])) {
                $validated[$field] = trim($validated[$field]);
            }
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(Request $request, $workspace, Project $project, string $action, int $sourceId, array $metadata): void
    {
        AuditLog::query()->create([
            'actor_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => 'research_source',
            'subject_id' => $sourceId,
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
