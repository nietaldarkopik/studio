<?php

namespace App\Jobs;

use App\Models\AiGeneration;
use App\Models\AuditLog;
use App\Services\AI\NineRouterClient;
use App\Services\Search\SearchClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class GenerateAiDraft implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct(public int $generationId)
    {
    }

    public function handle(NineRouterClient $nineRouter, SearchClient $search): void
    {
        $generation = AiGeneration::query()->find($this->generationId);
        if ($generation === null || $generation->status === 'completed') {
            return;
        }

        $generation->update([
            'status' => 'running',
            'started_at' => now(),
            'error_message' => null,
        ]);

        try {
            $draft = $this->draftFor($nineRouter, $search, $generation, $generation->task, $generation->input ?? []);
        } catch (Throwable $exception) {
            $generation->update([
                'status' => 'failed',
                'error_message' => $this->safeMessage($exception),
                'finished_at' => now(),
            ]);

            return;
        }

        $model = $draft['model'];
        unset($draft['model']);

        if ($generation->task === 'prompt_all') {
            $draft = [
                'prompts' => $draft['prompts'] ?? [],
                'created' => $this->storePrompts($generation, $draft['prompts'] ?? []),
            ];
        }

        $generation->update([
            'status' => 'completed',
            'model' => $model,
            'result' => $draft,
            'finished_at' => now(),
        ]);

        AuditLog::query()->create([
            'actor_id' => $generation->requested_by,
            'action' => 'ai.'.$generation->task.'_generated',
            'subject_type' => 'project',
            'subject_id' => $generation->project_id,
            'metadata' => [
                'workspace_id' => $generation->workspace_id,
                'task' => $generation->task,
                'model' => $model,
                'created' => $draft['created'] ?? null,
            ],
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $generation = AiGeneration::query()->find($this->generationId);
        if ($generation === null || ! $generation->isActive()) {
            return;
        }

        $generation->update([
            'status' => 'failed',
            'error_message' => $exception === null
                ? 'Draf AI gagal dibuat.'
                : $this->safeMessage($exception),
            'finished_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function draftFor(NineRouterClient $nineRouter, SearchClient $search, AiGeneration $generation, string $task, array $input): array
    {
        return match ($task) {
            'prompt' => $nineRouter->generatePromptDraft($input),
            'prompt_all' => $nineRouter->generatePromptBatchDrafts($input),
            'research' => $nineRouter->fillResearchSource($this->withPageText($search, $input)),
            'research_search' => $this->searchSources($nineRouter, $search, $input),
            'research_auto' => $this->autoResearch($generation),
            'research_verify' => app(\App\Services\Research\ResearchService::class)->verify($generation->project) + ['model' => config('services.nine_router.model')],
            default => throw new RuntimeException('Jenis draf AI tidak dikenal.', 422),
        };
    }

    /**
     * Lampirkan teks halaman sumber (bila dapat dibaca) agar AI mengisi draf dari isi asli.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function withPageText(SearchClient $search, array $input): array
    {
        $url = is_string($input['url'] ?? null) ? trim($input['url']) : '';

        if ($url !== '') {
            $page = $search->extract($url);
            if ($page !== null) {
                $input['page_text'] = $page['text'];
            }
        }

        return $input;
    }

    /**
     * Cari sumber di mesin pencari, lalu minta AI memilih dan meringkas kandidatnya.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function searchSources(NineRouterClient $nineRouter, SearchClient $search, array $input): array
    {
        $query = is_string($input['query'] ?? null) ? trim($input['query']) : '';

        $results = $search->search($query);
        if ($results === []) {
            throw new RuntimeException('Tavily tidak menemukan sumber untuk kata kunci ini.', 502);
        }

        $curated = $nineRouter->curateResearchCandidates([
            'project_title' => $input['project_title'] ?? null,
            'topic' => $input['topic'] ?? null,
            'query' => $query,
            'results' => $results,
        ]);

        $model = $curated['model'];
        unset($curated['model']);

        return [
            'query' => $query,
            'candidates' => $curated['candidates'],
            'count' => count($curated['candidates']),
            'model' => $model,
        ];
    }

    /**
     * Simpan draf prompt hasil AI sebagai prompt berstatus draf untuk adegan yang belum punya prompt.
     *
     * @param  array<int, array<string, mixed>>  $drafts
     */
    private function storePrompts(AiGeneration $generation, array $drafts): int
    {
        $project = $generation->project;
        if ($project === null) {
            return 0;
        }

        $taken = array_flip(array_filter(
            $project->prompts()->pluck('scene_id')->all(),
            fn ($sceneId) => $sceneId !== null,
        ));

        $created = 0;

        DB::transaction(function () use ($project, $generation, $drafts, $taken, &$created): void {
            foreach ($drafts as $draft) {
                $sceneId = (int) ($draft['scene_id'] ?? 0);
                $positive = is_string($draft['positive'] ?? null) ? trim($draft['positive']) : '';

                if ($sceneId === 0 || $positive === '' || isset($taken[$sceneId])) {
                    continue;
                }

                if (! $project->scenes()->whereKey($sceneId)->exists()) {
                    continue;
                }

                $prompt = $project->prompts()->create([
                    'scene_id' => $sceneId,
                    'created_by' => $generation->requested_by,
                    'status' => 'draft',
                    'version' => 1,
                    'title' => $draft['title'] ?? null,
                    'positive' => $positive,
                    'negative' => $draft['negative'] ?? null,
                    'style' => $draft['style'] ?? null,
                    'camera' => $draft['camera'] ?? null,
                    'lighting' => $draft['lighting'] ?? null,
                    'mood' => $draft['mood'] ?? null,
                    'notes' => $draft['notes'] ?? null,
                ]);

                $prompt->revisions()->create([
                    'created_by' => $generation->requested_by,
                    'version' => 1,
                    'snapshot' => [
                        'title' => $prompt->title,
                        'positive' => $prompt->positive,
                        'negative' => $prompt->negative,
                        'style' => $prompt->style,
                        'camera' => $prompt->camera,
                        'lighting' => $prompt->lighting,
                        'mood' => $prompt->mood,
                        'notes' => $prompt->notes,
                    ],
                ]);

                $taken[$sceneId] = true;
                $created++;
            }
        });

        return $created;
    }

    /**
     * @return array<string, mixed>
     */
    private function autoResearch(AiGeneration $generation): array
    {
        $service = app(\App\Services\Research\ResearchService::class);
        $project = $generation->project;
        $queries = $service->queriesFor($project);
        $created = $service->collect($project, $generation->requested_by, $queries);

        if ($created === 0 && $project->researchSources()->doesntExist()) {
            throw new RuntimeException('Riset otomatis tidak menemukan sumber untuk topik ini.', 502);
        }

        return ['created' => $created, 'queries' => $queries, 'domains' => $service->distinctDomains($project), 'model' => config('services.nine_router.model')];
    }

    private function safeMessage(Throwable $exception): string
    {
        $message = $exception->getMessage();

        return str_starts_with($message, '9Router ') || str_starts_with($message, 'Tavily ')
            ? $message
            : 'Draf AI gagal dibuat.';
    }
}
