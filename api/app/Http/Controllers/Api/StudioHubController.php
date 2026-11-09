<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiGeneration;
use App\Models\ProductionRun;
use App\Models\Prompt;
use App\Models\ResearchSource;
use App\Models\Scene;
use App\Services\ClipWize\ClipWizeClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Endpoint baca-saja untuk halaman modul Studio (Research, Scene Studio, Render, Audio, QC, dst.).
 */
class StudioHubController extends Controller
{
    public function research(Request $request, int $workspaceId): JsonResponse
    {
        $workspace = $this->workspace($request, $workspaceId);
        $projectIds = $workspace->projects()->pluck('title', 'id');

        $sources = ResearchSource::query()->whereIn('project_id', $projectIds->keys())->latest('id')->limit(200)->get()
            ->map(fn (ResearchSource $s) => [
                'id' => $s->id,
                'project_id' => $s->project_id,
                'project' => $projectIds[$s->project_id] ?? null,
                'title' => $s->title,
                'url' => $s->url,
                'publisher' => $s->publisher,
                'host' => preg_replace('/^www\./', '', (string) parse_url($s->url, PHP_URL_HOST)),
                'verified' => (bool) $s->verified,
                'key_findings' => $s->key_findings === null ? null : mb_substr($s->key_findings, 0, 400),
            ]);

        return response()->json([
            'sources' => $sources,
            'totals' => ['sources' => $sources->count(), 'verified' => $sources->where('verified', true)->count()],
        ]);
    }

    public function scenes(Request $request, int $workspaceId): JsonResponse
    {
        $workspace = $this->workspace($request, $workspaceId);
        $projects = $workspace->projects()->latest('id')->limit(30)->get(['id', 'title']);
        $scenes = Scene::query()->whereIn('project_id', $projects->pluck('id'))->orderBy('project_id')->orderBy('position')->get();
        $prompts = Prompt::query()->whereIn('project_id', $projects->pluck('id'))->whereNotNull('scene_id')->get()->keyBy('scene_id');

        return response()->json(['projects' => $projects->map(fn ($p) => [
            'id' => $p->id,
            'title' => $p->title,
            'scenes' => $scenes->where('project_id', $p->id)->values()->map(fn (Scene $s) => [
                'id' => $s->id,
                'position' => $s->position,
                'title' => $s->title,
                'narration' => $s->narration,
                'duration_seconds' => $s->duration_seconds,
                'camera' => $s->camera_direction,
                'prompt' => $prompts->has($s->id) ? mb_substr((string) $prompts[$s->id]->positive, 0, 300) : null,
                'prompt_status' => $prompts[$s->id]->status ?? null,
            ])->all(),
        ])->filter(fn ($p) => $p['scenes'] !== [])->values()->all()]);
    }

    public function renders(Request $request, int $workspaceId): JsonResponse
    {
        $runs = $this->runs($request, $workspaceId);

        return response()->json(['renders' => $runs->map(function (ProductionRun $run) {
            $timeline = $run->artifact('timeline', []);

            return [
                'run_id' => $run->id,
                'title' => $this->title($run),
                'status' => $run->stageState('render')['status'] ?? 'pending',
                'message' => $run->stageState('render')['message'] ?? null,
                'total_frames' => array_sum(array_column($timeline, 'frames')),
                'duration' => round(array_sum(array_column($timeline, 'duration')), 1),
                'scenes' => array_map(fn ($t) => [
                    'position' => $t['position'],
                    'frames' => $t['frames'],
                    'duration' => $t['duration'],
                    'preview' => $this->exists($run, 'frame', $t['position']),
                ], $timeline),
            ];
        })->values()->all()]);
    }

    public function audio(Request $request, int $workspaceId): JsonResponse
    {
        $runs = $this->runs($request, $workspaceId);

        return response()->json(['tracks' => $runs->flatMap(function (ProductionRun $run) {
            $scenes = $run->project?->scenes()->get()->keyBy('id') ?? collect();

            return collect($run->artifact('tts', []))->map(fn ($t) => [
                'run_id' => $run->id,
                'project' => $this->title($run),
                'position' => $t['position'],
                'scene' => $scenes[$t['scene_id']]->title ?? null,
                'text' => mb_substr((string) ($scenes[$t['scene_id']]->narration ?? ''), 0, 200),
                'duration' => $t['duration'],
                'available' => $this->exists($run, 'audio', $t['position']),
            ]);
        })->values()->all()]);
    }

    public function qc(Request $request, int $workspaceId): JsonResponse
    {
        $runs = $this->runs($request, $workspaceId);

        return response()->json(['reports' => $runs->filter(fn ($r) => $r->artifact('qc') !== null)->map(fn (ProductionRun $run) => [
            'run_id' => $run->id,
            'title' => $this->title($run),
            'status' => $run->status,
            'passed' => (bool) ($run->artifact('qc')['passed'] ?? false),
            'checks' => $run->artifact('qc')['checks'] ?? [],
            'contact_sheet' => $this->exists($run, 'contact'),
        ])->values()->all()]);
    }

    public function publishing(Request $request, int $workspaceId): JsonResponse
    {
        $runs = $this->runs($request, $workspaceId);

        return response()->json(['posts' => $runs->map(fn (ProductionRun $run) => [
            'run_id' => $run->id,
            'title' => $this->title($run),
            'status' => $run->status,
            'publish_stage' => $run->stageState('publish')['status'] ?? 'pending',
            'message' => $run->stageState('publish')['message'] ?? null,
            'post_url' => $run->artifact('post_url'),
            'post_id' => $run->artifact('post_id'),
            'qc_passed' => (bool) ($run->artifact('qc')['passed'] ?? false),
            'has_video' => $this->exists($run, 'video'),
            'can_approve' => $run->status === 'awaiting_approval',
        ])->values()->all()]);
    }

    public function media(Request $request, int $workspaceId): JsonResponse
    {
        $runs = $this->runs($request, $workspaceId);
        $items = [];

        foreach ($runs as $run) {
            $title = $this->title($run);
            foreach (['video' => 'Video akhir', 'contact' => 'Contact sheet'] as $kind => $label) {
                if ($this->exists($run, $kind)) {
                    $items[] = ['run_id' => $run->id, 'project' => $title, 'kind' => $kind, 'n' => null, 'label' => $label, 'type' => $kind === 'video' ? 'video' : 'image'];
                }
            }
            foreach ($run->artifact('tts', []) as $t) {
                if ($this->exists($run, 'audio', $t['position'])) {
                    $items[] = ['run_id' => $run->id, 'project' => $title, 'kind' => 'audio', 'n' => $t['position'], 'label' => 'Narasi adegan '.$t['position'], 'type' => 'audio'];
                }
            }
            foreach ($run->artifact('timeline', []) as $t) {
                if ($this->exists($run, 'frame', $t['position'])) {
                    $items[] = ['run_id' => $run->id, 'project' => $title, 'kind' => 'frame', 'n' => $t['position'], 'label' => 'Pratinjau adegan '.$t['position'], 'type' => 'image'];
                }
            }
        }

        return response()->json(['items' => $items]);
    }

    public function agents(Request $request, int $workspaceId): JsonResponse
    {
        $workspace = $this->workspace($request, $workspaceId);

        $tasks = [
            'prompt' => 'Prompt Designer', 'prompt_all' => 'Prompt Designer (batch)',
            'research' => 'Research Analyst', 'research_search' => 'Research Scout',
        ];

        $generations = AiGeneration::query()->where('workspace_id', $workspace->id)->latest('id')->limit(40)->get()
            ->map(fn (AiGeneration $g) => [
                'agent' => $tasks[$g->task] ?? $g->task,
                'status' => $g->status,
                'model' => $g->model,
                'error' => $g->error_message,
                'at' => $g->updated_at?->toIso8601String(),
            ]);

        $runs = $workspace->productionRuns()->count();

        return response()->json([
            'agents' => [
                ['name' => 'Showrunner', 'role' => 'Memilih tema, judul, dan format; menghindari topik terpakai', 'runs' => $runs],
                ['name' => 'Research Analyst', 'role' => 'Mencari ≥2 sumber independen via 9Router dan merangkum temuan', 'runs' => $generations->whereIn('agent', ['Research Analyst', 'Research Scout'])->count()],
                ['name' => 'Script Writer & Fact-checker', 'role' => 'Menulis naskah 95–160 kata dan memeriksanya terhadap sumber', 'runs' => $runs],
                ['name' => 'Prompt Designer', 'role' => 'Menyusun prompt visual dan spesifikasi render 3D', 'runs' => $generations->where('agent', 'like', 'Prompt Designer%')->count()],
                ['name' => 'Renderer', 'role' => 'Menjalankan Blender headless dan ffmpeg di PC ini', 'runs' => $workspace->productionRuns()->whereNotNull('artifacts')->count()],
                ['name' => 'Publisher', 'role' => 'Upload YouTube Shorts via ClipWize dengan QC dan batas harian', 'runs' => $workspace->productionRuns()->where('artifacts', 'like', '%post_id%')->count()],
            ],
            'activity' => $generations->values()->all(),
        ]);
    }

    public function analytics(Request $request, int $workspaceId): JsonResponse
    {
        $runs = $this->runs($request, $workspaceId);
        $local = [
            'runs' => $runs->count(),
            'completed' => $runs->where('status', 'completed')->count(),
            'published' => $runs->filter(fn ($r) => $r->artifact('post_id') !== null)->count(),
            'needs_review' => $runs->where('status', 'needs_review')->count(),
            'failed' => $runs->where('status', 'failed')->count(),
        ];

        $remote = null;
        $note = null;
        try {
            $remote = app(ClipWizeClient::class)->analytics(30);
        } catch (Throwable $exception) {
            $note = str_starts_with($exception->getMessage(), 'ClipWize') || str_starts_with($exception->getMessage(), 'Token')
                ? $exception->getMessage() : 'Analitik ClipWize belum tersedia.';
        }

        return response()->json(['local' => $local, 'clipwize' => $remote, 'note' => $note]);
    }

    /** Menayangkan berkas hasil produksi. Path hanya berasal dari artifact run, bukan input pengguna. */
    public function file(Request $request, int $workspaceId, int $runId, string $kind, ?int $n = null): BinaryFileResponse
    {
        $workspace = $this->workspace($request, $workspaceId);
        $run = $workspace->productionRuns()->whereKey($runId)->firstOrFail();
        $path = $this->resolve($run, $kind, $n);
        abort_if($path === null || ! is_file($path), 404);

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'mp4' => 'video/mp4', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'png' => 'image/png', default => 'application/octet-stream',
        };

        return response()->file($path, ['Content-Type' => $mime, 'Cache-Control' => 'private, max-age=60', 'X-Content-Type-Options' => 'nosniff']);
    }

    // ----------------------------------------------------------------- helpers

    private function resolve(ProductionRun $run, string $kind, ?int $n): ?string
    {
        $path = match ($kind) {
            'video' => $run->artifact('final_video'),
            'contact' => $run->artifact('contact_sheet'),
            'audio' => collect($run->artifact('tts', []))->firstWhere('position', $n)['file'] ?? null,
            'frame' => $this->framePath($run, $n),
            default => null,
        };

        if (! is_string($path)) {
            return null;
        }

        // Pastikan berkas berada di dalam folder run.
        $root = realpath((string) (config('services.production.storage_root') ?: storage_path('app/production')));
        $real = realpath($path);

        return $root !== false && $real !== false && str_starts_with($real, $root) ? $real : null;
    }

    private function framePath(ProductionRun $run, ?int $n): ?string
    {
        $item = collect($run->artifact('timeline', []))->firstWhere('position', $n);
        if ($item === null) {
            return null;
        }

        $root = (string) (config('services.production.storage_root') ?: storage_path('app/production'));
        $dir = $root.DIRECTORY_SEPARATOR.'run_'.$run->id.DIRECTORY_SEPARATOR.'frames'.DIRECTORY_SEPARATOR.sprintf('scene_%02d', $n);
        $frame = $dir.DIRECTORY_SEPARATOR.sprintf('%04d.png', max(1, intdiv((int) $item['frames'], 2)));

        return is_file($frame) ? $frame : null;
    }

    private function exists(ProductionRun $run, string $kind, ?int $n = null): bool
    {
        return $this->resolve($run, $kind, $n) !== null;
    }

    private function title(ProductionRun $run): string
    {
        return $run->project?->title ?? ($run->artifact('idea')['title'] ?? 'Run #'.$run->id);
    }

    /** @return \Illuminate\Support\Collection<int, ProductionRun> */
    private function runs(Request $request, int $workspaceId)
    {
        return $this->workspace($request, $workspaceId)->productionRuns()->with('project')->latest('id')->limit(30)->get();
    }

    private function workspace(Request $request, int $workspaceId)
    {
        return app(WorkspaceController::class)->authorizedWorkspace($request, $workspaceId);
    }
}
