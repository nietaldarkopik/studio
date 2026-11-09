<?php

namespace App\Services\Production;

use App\Jobs\GenerateAiDraft;
use App\Models\AiGeneration;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProductionRun;
use App\Models\ResearchSource;
use App\Models\Scene;
use App\Models\Script;
use App\Services\AI\NineRouterClient;
use App\Services\ClipWize\ClipWizeClient;
use App\Services\Research\ResearchService;
use App\Services\PolyPizza\PolyPizzaClient;
use App\Services\Search\SearchClient;
use App\Services\Sketchfab\SketchfabClient;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/**
 * Orkestrator produksi otomatis: ide → riset → naskah → storyboard → prompt → TTS →
 * render Blender → finalisasi → QC → upload. Setiap tahap tersimpan dan dapat dilanjutkan.
 */
class ProductionPipeline
{
    public const FORMATS = ['chain_build', 'mystery_investigation', 'flag_banter'];

    private const MIN_WORDS = 95;

    private const MAX_WORDS = 160;

    private const SHAPES = ['sphere', 'cube', 'capsule', 'cone'];

    private const CAMERAS = ['push_in', 'pull_out', 'orbit', 'pan'];

    private const OBJECT_TYPES = ['person', 'school', 'house', 'temple', 'tree', 'mountain', 'sun', 'moon', 'cloud', 'book', 'clock', 'laptop', 'phone', 'chart', 'arrow', 'coin', 'car', 'flag', 'magnifier', 'lightbulb', 'question', 'brain', 'water', 'fire', 'crowd', 'earth', 'planet', 'rocket', 'volcano', 'ship', 'plane', 'bridge'];

    private const ANIMS = ['none', 'bob', 'spin', 'grow', 'shake', 'wave'];

    private const PALETTES = [
        [['#0b1030', '#2a1b5e'], '#ffb74d'],
        [['#06231a', '#0f5a3f'], '#6ee7ff'],
        [['#2b0a14', '#6b1d34'], '#ffd166'],
        [['#07203a', '#124a7a'], '#ff8fab'],
        [['#1c1033', '#4b2a7b'], '#9bf6ff'],
    ];

    public function __construct(
        private readonly NineRouterClient $ai,
        private readonly SearchClient $search,
        private readonly ClipWizeClient $clipwize,
        private readonly MediaToolkit $media,
        private readonly BlenderRenderer $blender,
        private readonly ResearchService $research,
        private readonly SketchfabClient $sketchfab,
        private readonly PolyPizzaClient $polyPizza,
    ) {
    }

    public function run(ProductionRun $run): void
    {
        $run->forceFill([
            'status' => 'running',
            'error_message' => null,
            'started_at' => $run->started_at ?? now(),
            'finished_at' => null,
        ])->save();

        foreach (array_keys(ProductionRun::STAGES) as $stage) {
            $run->refresh();

            if ($run->status === 'cancelled') {
                return;
            }

            $state = $run->stageState($stage)['status'] ?? 'pending';
            if (in_array($state, ['completed', 'skipped'], true)) {
                continue;
            }

            $run->markStage($stage, 'running');

            try {
                $outcome = $this->{'stage'.ucfirst($stage)}($run);
            } catch (Throwable $exception) {
                $run->markStage($stage, 'failed', $this->safeMessage($exception));
                $run->forceFill([
                    'status' => 'failed',
                    'error_message' => $this->safeMessage($exception),
                    'finished_at' => now(),
                ])->save();

                return;
            }

            // Tahap dapat berhenti untuk menunggu manusia / peninjauan.
            if ($outcome !== null && $outcome['status'] !== 'completed') {
                $run->markStage($stage, $outcome['stage_status'] ?? $outcome['status'], $outcome['message']);
                $run->forceFill([
                    'status' => $outcome['status'],
                    'error_message' => $outcome['status'] === 'needs_review' ? $outcome['message'] : null,
                    'finished_at' => $outcome['status'] === 'awaiting_approval' ? null : now(),
                ])->save();

                return;
            }

            $run->markStage($stage, 'completed', $outcome['message'] ?? null);
        }

        $run->forceFill(['status' => 'completed', 'finished_at' => now()])->save();
        $this->audit($run, 'production.completed', ['post_url' => $run->artifact('post_url')]);
    }

    // ------------------------------------------------------------------ stages

    /** @return array<string, mixed>|null */
    private function stageIdeate(ProductionRun $run): ?array
    {
        if ($run->project_id !== null) {
            $project = $run->project;
            $run->setArtifact('idea', $run->artifact('idea') ?? [
                'theme' => $project->title,
                'description' => $project->topic,
                'hashtags' => [],
                'queries' => [$project->topic],
                'format' => $run->option('format', 'mystery_investigation'),
            ]);

            return ['status' => 'completed', 'message' => 'Memakai proyek yang sudah ada.'];
        }

        $format = $this->pickFormat($run);
        $avoid = $this->avoidList($run);
        $idea = null;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $candidate = $this->ai->generateIdea([
                'format' => $format,
                'hint' => $run->option('theme'),
                'avoid' => $avoid,
            ]);

            if (! $this->isDuplicateTitle($run, $candidate['title'], $avoid)) {
                $idea = $candidate;
                break;
            }

            $avoid[] = $candidate['title'];
        }

        if ($idea === null) {
            throw new RuntimeException('AI terus mengusulkan judul yang sudah pernah dipakai.');
        }

        $project = Project::query()->create([
            'workspace_id' => $run->workspace_id,
            'channel_id' => $run->channel_id,
            'created_by' => $run->requested_by,
            'title' => mb_substr($idea['title'], 0, 120),
            'topic' => $idea['topic'],
            'status' => 'draft',
            'mode' => 'autonomous',
        ]);

        $run->forceFill(['project_id' => $project->id])->save();
        $run->setArtifact('idea', [
            'theme' => $idea['theme'],
            'title' => $idea['title'],
            'topic' => $idea['topic'],
            'description' => $idea['description'],
            'hashtags' => $idea['hashtags'],
            'queries' => $idea['queries'],
            'format' => $format,
        ]);

        return ['status' => 'completed', 'message' => $idea['theme'].': '.$idea['title']];
    }

    /** @return array<string, mixed>|null */
    private function stageResearch(ProductionRun $run): ?array
    {
        $project = $run->project;
        $idea = $run->artifact('idea', []);
        $required = $this->researchRequired($run);

        if ($run->option('existing_project', false) && ! $required) {
            return ['status' => 'completed', 'message' => 'Dilewati: memakai proyek manual (riset opsional).'];
        }

        try {
            $this->research->collect($project, $run->requested_by, $idea['queries'] ?? [$project->topic]);
        } catch (Throwable $exception) {
            if ($required) {
                throw $exception;
            }
        }

        $hosts = $this->research->distinctDomains($project->refresh());
        if ($hosts < 2) {
            if ($required) {
                throw new RuntimeException("Riset hanya menemukan {$hosts} sumber independen; minimal 2 diperlukan.");
            }

            return ['status' => 'completed', 'message' => "Riset opsional: {$hosts} situs ditemukan, dilanjutkan tanpa verifikasi sumber."];
        }

        return ['status' => 'completed', 'message' => $project->researchSources()->count()." sumber dari {$hosts} situs berbeda."];
    }

    /** @return array<string, mixed>|null */
    private function stageScript(ProductionRun $run): ?array
    {
        $project = $run->project;

        if ($run->option('existing_project', false) && filled($project->script?->narration)) {
            return ['status' => 'completed', 'message' => 'Memakai naskah yang sudah Anda tulis ('.$project->script->word_count.' kata).'];
        }

        $format = ($run->artifact('idea', [])['format'] ?? null) ?: 'mystery_investigation';
        $sources = $project->researchSources()->get()->filter(fn (ResearchSource $s) => filled($s->key_findings));

        $facts = $sources->map(fn (ResearchSource $s, int $i) => '['.($i + 1).'] '.$s->title.': '.mb_substr((string) $s->key_findings, 0, 1200))->implode("\n");
        $sourcePayload = $sources->map(fn (ResearchSource $s) => ['title' => $s->title, 'findings' => (string) $s->key_findings])->values()->all();

        $feedback = '';
        $lastIssues = [];
        $draft = null;
        $fallback = null;
        $checked = false;
        $required = $this->researchRequired($run);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $candidate = $this->ai->generateScriptDraft($project->topic, $format, $facts.$feedback);

            if ($candidate['word_count'] < self::MIN_WORDS || $candidate['word_count'] > self::MAX_WORDS) {
                $feedback = "\n\nCATATAN REVISI: naskah sebelumnya {$candidate['word_count']} kata. Wajib ".self::MIN_WORDS.'–'.self::MAX_WORDS.' kata.';
                $lastIssues = ["Panjang naskah {$candidate['word_count']} kata (target ".self::MIN_WORDS.'–'.self::MAX_WORDS.').'];
                continue;
            }

            if (count($candidate['scenes']) < 3) {
                $feedback = "\n\nCATATAN REVISI: pecah naskah menjadi minimal 4 adegan.";
                $lastIssues = ['Breakdown adegan kurang dari 3.'];
                continue;
            }

            $fallback = $candidate;

            if ($sourcePayload === []) {
                $draft = $candidate; // tanpa sumber riset: tidak ada yang dicocokkan
                break;
            }

            $check = $this->ai->factCheckScript($candidate['narration'], $sourcePayload);
            if (! $check['supported']) {
                $lastIssues = $check['issues'];
                $feedback = "\n\nCATATAN REVISI FAKTA — hapus/ubah klaim berikut karena tidak didukung sumber:\n- ".implode("\n- ", $check['issues']);
                continue;
            }

            $draft = $candidate;
            $checked = true;
            break;
        }

        if ($draft === null && ! $required && $fallback !== null) {
            $draft = $fallback; // riset opsional: pakai naskah terbaik walau fact-check belum bersih
        }

        if ($draft === null) {
            return [
                'status' => 'needs_review',
                'message' => 'Naskah belum lolos pemeriksaan otomatis: '.implode(' | ', array_slice($lastIssues, 0, 4)),
            ];
        }

        $script = $project->script()->first();
        if ($script === null) {
            $project->script()->create([
                'format' => $format,
                'narration' => $draft['narration'],
                'word_count' => $draft['word_count'],
                'version' => 1,
                'status' => 'draft',
            ]);
        } else {
            $script->update([
                'format' => $format,
                'narration' => $draft['narration'],
                'word_count' => $draft['word_count'],
                'version' => $script->version + 1,
            ]);
        }

        // Naskah lolos fact-check terhadap >= 2 situs independen: tandai sumber terverifikasi.
        if ($checked && $this->research->distinctDomains($project) >= 2) {
            $project->researchSources()->update(['verified' => true]);
        }

        $run->setArtifact('draft_scenes', $draft['scenes']);

        $note = $checked ? 'lolos fact-check' : ($sourcePayload === [] ? 'tanpa sumber riset' : 'fact-check belum bersih (riset opsional)');

        return ['status' => 'completed', 'message' => $draft['word_count']." kata, {$note}."];
    }

    /** @return array<string, mixed>|null */
    private function stageStoryboard(ProductionRun $run): ?array
    {
        $project = $run->project;
        if ($project->scenes()->exists()) {
            return ['status' => 'completed', 'message' => 'Adegan sudah ada.'];
        }

        $scenes = $run->artifact('draft_scenes', []);
        if ($scenes === []) {
            throw new RuntimeException('Tidak ada breakdown adegan dari tahap naskah.');
        }

        foreach (array_values($scenes) as $i => $scene) {
            $project->scenes()->create([
                'position' => $i + 1,
                'title' => mb_substr((string) $scene['title'], 0, 120),
                'narration' => $scene['narration'] ?? null,
                'visual_description' => $scene['visual_description'] ?? null,
                'camera_direction' => $scene['camera_direction'] ?? null,
                'duration_seconds' => $scene['duration_seconds'] ?? 5,
                'status' => 'draft',
            ]);
        }

        return ['status' => 'completed', 'message' => count($scenes).' adegan.'];
    }

    /** @return array<string, mixed>|null */
    private function stagePrompts(ProductionRun $run): ?array
    {
        $project = $run->project;
        $withPrompt = $project->prompts()->whereNotNull('scene_id')->pluck('scene_id')->all();
        $pending = $project->scenes()->get()->reject(fn (Scene $s) => in_array($s->id, $withPrompt, true))->values();

        if ($pending->isEmpty()) {
            return ['status' => 'completed', 'message' => 'Semua adegan sudah punya prompt.'];
        }

        $generation = AiGeneration::query()->create([
            'workspace_id' => $run->workspace_id,
            'project_id' => $project->id,
            'requested_by' => $run->requested_by,
            'task' => 'prompt_all',
            'status' => 'queued',
            'input' => [
                'project_title' => $project->title,
                'topic' => $project->topic,
                'scenes' => $pending->map(fn (Scene $s) => [
                    'scene_id' => $s->id,
                    'title' => $s->title,
                    'narration' => $s->narration,
                    'visual_description' => $s->visual_description,
                    'camera_direction' => $s->camera_direction,
                ])->all(),
            ],
        ]);

        (new GenerateAiDraft($generation->id))->handle($this->ai, $this->search);

        $generation->refresh();
        if ($generation->status !== 'completed') {
            throw new RuntimeException($generation->error_message ?: 'Pembuatan prompt visual gagal.');
        }

        return ['status' => 'completed', 'message' => ($generation->result['created'] ?? 0).' prompt dibuat.'];
    }

    /** @return array<string, mixed>|null */
    private function stageTts(ProductionRun $run): ?array
    {
        $dir = $this->dir($run, 'audio');
        $items = [];

        foreach ($run->project->scenes()->get() as $scene) {
            $text = trim((string) $scene->narration);
            if ($text === '') {
                continue;
            }

            $file = $dir.DIRECTORY_SEPARATOR.sprintf('scene_%02d.mp3', $scene->position);
            if (! is_file($file) || filesize($file) === 0) {
                $tts = $this->clipwize->tts($text);
                file_put_contents($file, $tts['bytes']);
            }

            $items[] = [
                'scene_id' => $scene->id,
                'position' => $scene->position,
                'file' => $file,
                'duration' => round($this->media->duration($file), 3),
            ];
        }

        if ($items === []) {
            throw new RuntimeException('Tidak ada narasi adegan untuk dijadikan suara.');
        }

        $run->setArtifact('tts', $items);

        return ['status' => 'completed', 'message' => count($items).' berkas audio, '.round(array_sum(array_column($items, 'duration')), 1).' dtk.'];
    }

    /** @return array<string, mixed>|null */
    private function stageRender(ProductionRun $run): ?array
    {
        $project = $run->project;
        $format = $run->artifact('idea')['format'] ?? 'mystery_investigation';
        $gap = (float) config('services.production.scene_gap_seconds', 0.35);
        $fps = (int) config('services.production.render_fps', 10);
        $tts = collect($run->artifact('tts', []))->keyBy('scene_id');
        $scenes = $project->scenes()->get()->filter(fn (Scene $s) => $tts->has($s->id))->values();
        $prompts = $project->prompts()->get()->keyBy('scene_id');

        $aiSpecs = $run->artifact('render_specs');
        if ($aiSpecs === null) {
            try {
                $aiSpecs = $this->ai->generateRenderSpecs($project->title, $format, $scenes->map(fn (Scene $s) => [
                    'scene_id' => $s->id,
                    'title' => $s->title,
                    'narration' => $s->narration,
                    'visual' => $prompts->get($s->id)?->positive ?? $s->visual_description,
                ])->all(), $this->modelSourcesConfigured());
            } catch (Throwable) {
                $aiSpecs = []; // desain bawaan deterministik bila AI gagal
            }
            $run->setArtifact('render_specs', $aiSpecs);
        }

        $specScenes = [];
        $timeline = [];
        $cursor = 0.0;
        foreach ($scenes as $i => $scene) {
            $duration = (float) $tts[$scene->id]['duration'] + $gap;
            $specScenes[] = ['index' => $scene->position, 'frames' => max(4, (int) ceil($duration * $fps))]
                + $this->sanitizeSpec($aiSpecs[$scene->id] ?? [], $i, $scene->title);
            $timeline[] = [
                'scene_id' => $scene->id,
                'position' => $scene->position,
                'start' => round($cursor, 3),
                'audio' => (float) $tts[$scene->id]['duration'],
                'duration' => round($duration, 3),
                'frames' => max(4, (int) ceil($duration * $fps)),
            ];
            $cursor += $duration;
        }

        [$specScenes, $credits] = $this->resolveModels($run, $specScenes);
        $run->setArtifact('credits', $credits);

        $framesDir = $this->dir($run, 'frames');
        $spec = [
            'out_dir' => $framesDir,
            'width' => (int) config('services.production.render_width', 540),
            'height' => (int) config('services.production.render_height', 960),
            'fps' => $fps,
            'samples' => (int) config('services.production.render_samples', 8),
            'scenes' => $specScenes,
        ];

        // Spec berubah (mis. durasi TTS baru) => buang frame lama agar tidak tercampur.
        $hash = md5(json_encode($spec));
        if ($run->artifact('render_hash') !== null && $run->artifact('render_hash') !== $hash) {
            File::deleteDirectory($framesDir);
            File::ensureDirectoryExists($framesDir);
        }
        $run->setArtifact('render_hash', $hash);
        $run->setArtifact('timeline', $timeline);

        $this->blender->render($spec, $this->dir($run).DIRECTORY_SEPARATOR.'spec.json');

        foreach ($timeline as $item) {
            $count = $this->blender->countFrames($framesDir.DIRECTORY_SEPARATOR.sprintf('scene_%02d', $item['position']));
            if ($count < $item['frames']) {
                throw new RuntimeException("Render adegan {$item['position']} tidak lengkap ({$count}/{$item['frames']} frame).");
            }
        }

        return ['status' => 'completed', 'message' => array_sum(array_column($timeline, 'frames')).' frame dirender.'];
    }

    /** @return array<string, mixed>|null */
    private function stageFinalize(ProductionRun $run): ?array
    {
        $timeline = $run->artifact('timeline', []);
        $tts = collect($run->artifact('tts', []))->keyBy('scene_id');
        $fps = (int) config('services.production.render_fps', 10);
        $dir = $this->dir($run);
        $clipsDir = $this->dir($run, 'clips');
        $clips = [];
        $audio = [];
        $durations = [];

        foreach ($timeline as $item) {
            $clip = $clipsDir.DIRECTORY_SEPARATOR.sprintf('scene_%02d.mp4', $item['position']);
            $this->media->sceneClip(
                $dir.DIRECTORY_SEPARATOR.'frames'.DIRECTORY_SEPARATOR.sprintf('scene_%02d', $item['position']),
                $fps,
                $item['duration'],
                $clip,
            );
            $clips[] = $clip;
            $audio[] = $tts[$item['scene_id']]['file'];
            $durations[] = $item['duration'];
        }

        $silent = $dir.DIRECTORY_SEPARATOR.'video_silent.mp4';
        $narration = $dir.DIRECTORY_SEPARATOR.'narration.wav';
        $srt = $dir.DIRECTORY_SEPARATOR.'caption.srt';
        $final = $dir.DIRECTORY_SEPARATOR.'final.mp4';

        $this->media->concatVideos($clips, $silent);
        $this->media->narrationTrack($audio, $durations, $narration);
        file_put_contents($srt, $this->buildSrt($run));

        $total = array_sum($durations);
        $logo = base_path((string) config('services.production.logo_file'));
        $bgm = config('services.production.bgm_file');

        $this->media->finalize($silent, $narration, $final, [
            'bgm' => is_string($bgm) && is_file($bgm) ? $bgm : null,
            'logo' => is_file($logo) ? $logo : null,
            'srt' => $srt,
            'total' => $total,
        ], $dir);

        $run->setArtifact('final_video', $final);
        $run->setArtifact('caption_file', $srt);

        return ['status' => 'completed', 'message' => 'final.mp4 ('.round($total, 1).' dtk).'];
    }

    /** @return array<string, mixed>|null */
    private function stageQc(ProductionRun $run): ?array
    {
        $final = (string) $run->artifact('final_video');
        $checks = [];
        $add = function (string $name, bool $ok, string $detail) use (&$checks): void {
            $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
        };

        if (! is_file($final)) {
            throw new RuntimeException('Berkas video akhir tidak ditemukan untuk QC.');
        }

        $probe = $this->media->probe($final);
        $video = collect($probe['streams'] ?? [])->firstWhere('codec_type', 'video') ?? [];
        $hasAudio = collect($probe['streams'] ?? [])->contains(fn ($s) => ($s['codec_type'] ?? null) === 'audio');
        $duration = (float) ($probe['format']['duration'] ?? 0);
        $min = (float) config('services.production.min_duration', 20);
        $max = (float) config('services.production.max_duration', 180);

        $add('Resolusi 1080x1920', ($video['width'] ?? 0) === 1080 && ($video['height'] ?? 0) === 1920, ($video['width'] ?? '?').'x'.($video['height'] ?? '?'));
        $add('Durasi wajar', $duration >= $min && $duration <= $max, round($duration, 1)." dtk (batas {$min}–{$max})");
        $add('Ada trek audio', $hasAudio, $hasAudio ? 'ya' : 'tidak ada');

        $volume = $hasAudio ? $this->media->meanVolume($final) : null;
        $add('Narasi terdengar', $volume !== null && $volume > -45, $volume === null ? 'tak terdeteksi' : round($volume, 1).' dB');

        $stats = $this->media->brightnessStats($final);
        $add('Visual tidak hitam/beku', $stats['mean'] > 12 && ($stats['max'] - $stats['min']) >= 0.5, 'kecerahan rata-rata '.round($stats['mean'], 1));

        $sheet = $this->dir($run).DIRECTORY_SEPARATOR.'contact.png';
        try {
            $this->media->contactSheet($final, $sheet);
            $run->setArtifact('contact_sheet', $sheet);
        } catch (Throwable) {
            // peta kontak hanyalah bahan tinjauan; kegagalannya tidak menggugurkan QC
        }

        $project = $run->project;
        $words = (int) ($project->script?->word_count ?? 0);
        $manual = (bool) $run->option('existing_project', false);
        $inRange = $words >= self::MIN_WORDS && $words <= self::MAX_WORDS;
        $add('Naskah 95–160 kata', $inRange || ($manual && $words > 0), $inRange ? "{$words} kata" : "{$words} kata (naskah manual, di luar target 95–160)");
        $hosts = $this->distinctHosts($project);
        $sourcesOk = $hosts >= 2 && $project->researchSources()->where('verified', true)->count() >= 2;
        $add('≥2 sumber independen terverifikasi', $sourcesOk || ! $this->researchRequired($run), $sourcesOk ? "{$hosts} situs" : "{$hosts} situs (opsional, tidak wajib)");
        $add('Judul valid', $project->title !== '' && mb_strlen($project->title) <= 100, mb_strlen($project->title).' karakter');
        $promptsOk = $project->prompts()->count() >= $project->scenes()->count();
        $add('Semua adegan punya prompt', $promptsOk || $manual, $project->prompts()->count().'/'.$project->scenes()->count().($promptsOk ? '' : ' (opsional untuk proyek manual)'));

        $failed = array_values(array_filter($checks, fn ($c) => ! $c['ok']));
        $run->setArtifact('qc', ['passed' => $failed === [], 'checks' => $checks]);

        if ($failed !== []) {
            return [
                'status' => 'needs_review',
                'message' => 'QC otomatis gagal: '.implode('; ', array_map(fn ($c) => $c['name'].' ('.$c['detail'].')', $failed)),
            ];
        }

        return ['status' => 'completed', 'message' => count($checks).' pemeriksaan lolos.'];
    }

    /** @return array<string, mixed>|null */
    private function stagePublish(ProductionRun $run): ?array
    {
        if ($run->artifact('post_id') !== null) {
            return ['status' => 'completed', 'message' => 'Sudah dipublikasikan: '.$run->artifact('post_url')];
        }

        $auto = (bool) $run->option('auto_publish', config('services.production.auto_publish', false));
        if (! $auto && ! $run->option('publish_approved', false)) {
            return [
                'status' => 'awaiting_approval',
                'stage_status' => 'awaiting_approval',
                'message' => 'Video siap. Menunggu persetujuan untuk upload ke YouTube.',
            ];
        }

        // Publikasi bersifat publik & tidak idempoten: percobaan sebelumnya tanpa hasil jelas tidak diulang.
        if ($run->artifact('publish_attempt') !== null && $run->artifact('clip_id') !== null && $run->artifact('post_id') === null) {
            return [
                'status' => 'needs_review',
                'message' => 'Percobaan publikasi sebelumnya tidak punya hasil jelas. Periksa ClipWize/YouTube sebelum mengulang.',
            ];
        }

        $channel = $run->channel;
        $accountId = (int) ($channel?->external_id ?: config('services.clipwize.account_id'));
        $accountName = (string) ($channel?->name ?: config('services.clipwize.account_name'));
        if ($accountId <= 0 || $accountName === '') {
            return ['status' => 'needs_review', 'message' => 'Akun ClipWize belum diatur (external_id kanal atau CLIPWIZE_ACCOUNT_ID).'];
        }

        $this->clipwize->assertAccount($accountId, $accountName);

        $project = $run->project;
        $posts = $this->clipwize->recentPosts($accountId);
        $today = now('Asia/Jakarta')->toDateString();
        $todayCount = 0;
        foreach ($posts as $post) {
            $created = $post['published_at'] ?? $post['scheduled_at'] ?? $post['created_at'] ?? null;
            if ($created !== null && strtotime((string) $created) !== false && date('Y-m-d', strtotime((string) $created) + 7 * 3600) === $today) {
                $todayCount++;
            }
            $existing = (string) ($post['title'] ?? $post['caption'] ?? '');
            if ($existing !== '' && mb_strtolower(trim($existing)) === mb_strtolower(trim($project->title))) {
                return ['status' => 'needs_review', 'message' => 'Judul yang sama sudah pernah diunggah ke kanal ini.'];
            }
        }

        $limit = (int) config('services.production.max_posts_per_day', 1);
        if ($limit > 0 && $todayCount >= $limit) {
            return ['status' => 'needs_review', 'message' => "Batas {$limit} upload per hari sudah tercapai (hari ini {$todayCount})."];
        }

        $idea = $run->artifact('idea', []);
        $tags = array_values(array_unique(array_merge($idea['hashtags'] ?? [], ['Shorts'])));
        $creditsText = SketchfabClient::creditsText($run->artifact('credits', []));
        $caption = trim(($idea['description'] ?? $project->topic)."\n\n".($creditsText !== '' ? $creditsText."\n\n" : '').implode(' ', array_map(fn ($t) => '#'.$t, $tags)));

        $clipId = $run->artifact('clip_id');
        if ($clipId === null) {
            $clip = $this->clipwize->importClip((string) $run->artifact('final_video'), [
                'title' => $project->title,
                'caption' => $caption,
                'hashtags' => $tags,
            ]);
            $clipId = (int) $clip['id'];
            $run->setArtifact('clip_id', $clipId);
        }

        $run->setArtifact('publish_attempt', now()->toIso8601String());

        try {
            $post = $this->clipwize->publish((int) $clipId, $accountId);
        } catch (Throwable $exception) {
            if ($exception->getCode() === ClipWizeClient::UNKNOWN_OUTCOME) {
                return ['status' => 'needs_review', 'message' => 'Status publikasi TIDAK diketahui. Periksa ClipWize sebelum mengulang.'];
            }

            // Penolakan jelas dari API (mis. 422): tidak ada post dibuat; aman untuk dicoba lagi manual.
            $run->setArtifact('publish_attempt', null);
            throw $exception;
        }

        $run->setArtifact('post_id', (int) $post['id']);

        $final = $this->awaitPost($run, (int) $post['id'], $post);
        if (in_array($final['status'] ?? '', ['failed', 'cancelled'], true)) {
            return ['status' => 'needs_review', 'message' => 'YouTube/ClipWize melaporkan '.$final['status'].': '.mb_substr((string) ($final['error_message'] ?? 'tanpa pesan'), 0, 200)];
        }

        $url = $final['post_url'] ?? $final['url'] ?? null;
        $run->setArtifact('post_url', $url);
        $run->setArtifact('youtube_video_id', is_string($url) && preg_match('~(?:v=|shorts/|youtu\.be/)([\w-]{6,})~', $url, $m) ? $m[1] : null);
        $this->audit($run, 'production.published', ['post_id' => $post['id'], 'post_url' => $url]);

        return ['status' => 'completed', 'message' => $url ? "Terbit: {$url}" : 'Terbit (status: '.($final['status'] ?? '?').').'];
    }

    // ----------------------------------------------------------------- helpers

    /**
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    private function awaitPost(ProductionRun $run, int $postId, array $post): array
    {
        $interval = (int) config('services.production.publish_poll_interval', 15);
        $attempts = (int) config('services.production.publish_poll_attempts', 40);

        for ($i = 0; $i < $attempts; $i++) {
            if (in_array($post['status'] ?? '', ['published', 'failed', 'cancelled'], true)) {
                return $post;
            }

            if ($interval > 0) {
                sleep($interval);
            }

            try {
                $post = $this->clipwize->postStatus($postId);
            } catch (Throwable) {
                // pembacaan status boleh gagal sementara; post sudah tercatat
            }
        }

        return $post;
    }

    /**
     * Ganti objek `model` dengan berkas glTF Sketchfab yang diunduh; bila gagal/tidak dikonfigurasi,
     * objek memakai `fallback` (objek prosedural). Mengembalikan adegan dan daftar kredit lisensi.
     *
     * @param  array<int, array<string, mixed>>  $scenes
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function resolveModels(ProductionRun $run, array $scenes): array
    {
        $credits = [];
        $cache = (string) (config('services.production.storage_root') ?: storage_path('app/production')).DIRECTORY_SEPARATOR.'assets';

        foreach ($scenes as &$scene) {
            $models = 0;
            foreach ($scene['objects'] ?? [] as &$object) {
                if (($object['type'] ?? null) !== 'model') {
                    continue;
                }

                $models++;
                try {
                    if ($models > 2) {
                        throw new RuntimeException('model dilewati');
                    }

                    [$path, $credit] = $this->fetchModel((string) $object['query'], $cache);
                    $object['model_path'] = $path;
                    $credits[$credit['source'].':'.$credit['id']] = $credit;
                } catch (Throwable) {
                    unset($object['model_path']); // Blender memakai `fallback` saat model_path kosong
                }
            }
            unset($object);
        }
        unset($scene);

        return [$scenes, array_values($credits)];
    }

    private function modelSourcesConfigured(): bool
    {
        foreach ($this->modelSources() as $source) {
            if (($source === 'polypizza' && $this->polyPizza->isConfigured()) || ($source === 'sketchfab' && $this->sketchfab->isConfigured())) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function modelSources(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) config('services.production.model_sources', 'polypizza,sketchfab')))));
    }

    /**
     * Cari model di sumber yang terkonfigurasi (berurutan) lalu unduh.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function fetchModel(string $query, string $cacheRoot): array
    {
        foreach ($this->modelSources() as $source) {
            try {
                if ($source === 'polypizza' && $this->polyPizza->isConfigured()) {
                    $found = $this->polyPizza->find($query);
                    if ($found !== null) {
                        $path = $this->polyPizza->download($found, $cacheRoot.DIRECTORY_SEPARATOR.'polypizza');

                        return [$path, ['source' => 'Poly Pizza', 'id' => $found['uid'], 'name' => $found['name'], 'author' => $found['author'], 'url' => $found['url'], 'license' => $found['license'], 'license_slug' => $found['license_slug']]];
                    }
                }

                if ($source === 'sketchfab' && $this->sketchfab->isConfigured()) {
                    $found = $this->sketchfab->find($query);
                    if ($found !== null) {
                        $path = $this->sketchfab->download($found['uid'], $cacheRoot.DIRECTORY_SEPARATOR.'sketchfab');

                        return [$path, ['source' => 'Sketchfab', 'id' => $found['uid'], 'name' => $found['name'], 'author' => $found['author'], 'url' => $found['url'], 'license' => $found['license'], 'license_slug' => $found['license_slug']]];
                    }
                }
            } catch (Throwable) {
                continue; // sumber ini gagal; coba sumber berikutnya
            }
        }

        throw new RuntimeException('model tidak ditemukan');
    }

    private function researchRequired(ProductionRun $run): bool
    {
        return (bool) $run->option('require_research', config('services.production.require_research', false));
    }

    private function pickFormat(ProductionRun $run): string
    {
        $forced = $run->option('format');
        if (is_string($forced) && in_array($forced, self::FORMATS, true)) {
            return $forced;
        }

        $last = Script::query()
            ->whereIn('project_id', Project::query()->where('workspace_id', $run->workspace_id)->pluck('id'))
            ->latest('id')
            ->value('format');

        $index = array_search($last, self::FORMATS, true);

        return self::FORMATS[$index === false ? 0 : ($index + 1) % count(self::FORMATS)];
    }

    /**
     * @return array<int, string>
     */
    private function avoidList(ProductionRun $run): array
    {
        $avoid = Project::query()
            ->where('workspace_id', $run->workspace_id)
            ->latest('id')
            ->limit(60)
            ->get(['title', 'topic'])
            ->flatMap(fn ($p) => [$p->title, $p->topic])
            ->all();

        $file = (string) config('services.production.used_topics_file');
        if ($file !== '' && is_file($file) && is_readable($file)) {
            $lines = preg_split('/\R/u', (string) file_get_contents($file)) ?: [];
            $avoid = array_merge($avoid, array_slice(array_values(array_filter(array_map('trim', $lines))), -40));
        }

        try {
            $accountId = (int) (config('services.clipwize.account_id') ?: 0);
            if ($accountId > 0) {
                foreach ($this->clipwize->recentPosts($accountId) as $post) {
                    $title = $post['title'] ?? null;
                    if (is_string($title) && $title !== '') {
                        $avoid[] = $title;
                    }
                }
            }
        } catch (Throwable) {
            // daftar hindari dari ClipWize bersifat opsional
        }

        return array_values(array_unique(array_filter($avoid, fn ($v) => is_string($v) && $v !== '')));
    }

    /**
     * @param  array<int, string>  $avoid
     */
    private function isDuplicateTitle(ProductionRun $run, string $title, array $avoid): bool
    {
        $needle = mb_strtolower(trim($title));

        foreach ($avoid as $existing) {
            if (mb_strtolower(trim($existing)) === $needle) {
                return true;
            }
        }

        return false;
    }

    private function distinctHosts(Project $project): int
    {
        $hosts = [];
        foreach ($project->researchSources()->pluck('url') as $url) {
            $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
            $host = preg_replace('/^www\./', '', $host) ?? $host;
            $parts = explode('.', $host);
            $hosts[] = implode('.', array_slice($parts, -2));
        }

        return count(array_unique(array_filter($hosts)));
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function sanitizeSpec(array $raw, int $index, string $fallbackTitle): array
    {
        [$bg, $accent] = self::PALETTES[$index % count(self::PALETTES)];
        $hex = fn ($v, string $default) => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? $v : $default;

        $rawBg = is_array($raw['bg'] ?? null) ? array_values($raw['bg']) : [];
        $characters = [];
        foreach (array_slice((array) ($raw['characters'] ?? []), 0, 3) as $c) {
            if (! is_array($c)) {
                continue;
            }
            $stripes = array_values(array_filter(array_map(
                fn ($s) => is_string($s) && preg_match('/^#[0-9a-fA-F]{6}$/', $s) ? $s : null,
                array_slice((array) ($c['stripes'] ?? []), 0, 3),
            )));
            $characters[] = [
                'shape' => in_array($c['shape'] ?? null, self::SHAPES, true) ? $c['shape'] : 'sphere',
                'color' => $hex($c['color'] ?? null, '#6ee7ff'),
                'stripes' => count($stripes) >= 2 ? $stripes : [],
                'stripe_dir' => ($c['stripe_dir'] ?? 'h') === 'v' ? 'v' : 'h',
                'label' => mb_substr(trim((string) ($c['label'] ?? '')), 0, 16),
                'speaks' => (bool) ($c['speaks'] ?? true),
            ];
        }
        if ($characters === []) {
            $characters[] = ['shape' => 'sphere', 'color' => '#6ee7ff', 'stripes' => [], 'stripe_dir' => 'h', 'label' => '', 'speaks' => true];
        }

        $props = [];
        foreach (array_slice((array) ($raw['props'] ?? []), 0, 3) as $p) {
            if (! is_array($p)) {
                continue;
            }
            $props[] = [
                'shape' => in_array($p['shape'] ?? null, self::SHAPES, true) ? $p['shape'] : 'sphere',
                'color' => $hex($p['color'] ?? null, '#ffb74d'),
                'x' => max(-3, min(3, (float) ($p['x'] ?? 0))),
                'y' => max(1, min(4, (float) ($p['y'] ?? 2))),
                'z' => max(0.5, min(4, (float) ($p['z'] ?? 2))),
                'scale' => max(0.2, min(0.8, (float) ($p['scale'] ?? 0.4))),
            ];
        }

        $objects = [];
        foreach (array_slice((array) ($raw['objects'] ?? []), 0, 8) as $o) {
            $isModel = is_array($o) && ($o['type'] ?? null) === 'model' && is_string($o['query'] ?? null) && trim($o['query']) !== '';
            if (! is_array($o) || (! $isModel && ! in_array($o['type'] ?? null, self::OBJECT_TYPES, true))) {
                continue;
            }
            $stripes = array_values(array_filter(array_map(
                fn ($c) => is_string($c) && preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : null,
                array_slice((array) ($o['stripes'] ?? []), 0, 3),
            )));
            $objects[] = [
                'type' => $o['type'],
                'color' => $hex($o['color'] ?? null, '#6ee7ff'),
                'x' => max(-7, min(7, (float) ($o['x'] ?? 0))),
                'y' => max(-3, min(7, (float) ($o['y'] ?? 0))),
                'scale' => max(0.5, min(2.2, (float) ($o['scale'] ?? 1))),
                'label' => mb_substr(trim((string) ($o['label'] ?? '')), 0, 16),
                'anim' => in_array($o['anim'] ?? null, self::ANIMS, true) ? $o['anim'] : 'none',
                'speaks' => (bool) ($o['speaks'] ?? false),
                'stripes' => count($stripes) >= 2 ? $stripes : [],
                'stripe_dir' => ($o['stripe_dir'] ?? 'h') === 'v' ? 'v' : 'h',
            ] + ($isModel ? [
                'query' => mb_substr(preg_replace('/[^\p{L}\p{N} \-]/u', '', trim($o['query'])) ?? '', 0, 60),
                'fallback' => in_array($o['fallback'] ?? null, self::OBJECT_TYPES, true) ? $o['fallback'] : 'lightbulb',
                'size' => max(1.0, min(7.0, (float) ($o['size'] ?? 3.0))),
            ] : []);
        }

        // Tanpa komposisi dari AI: tokoh penjelas + buku agar adegan tetap berisi (bukan bola kosong).
        if ($objects === []) {
            $objects = [
                ['type' => 'person', 'color' => '#e63946', 'x' => -1.5, 'y' => -1, 'scale' => 1.4, 'label' => '', 'anim' => 'wave', 'speaks' => true, 'stripes' => [], 'stripe_dir' => 'h'],
                ['type' => 'book', 'color' => '#457b9d', 'x' => 2, 'y' => -1, 'scale' => 1.6, 'label' => '', 'anim' => 'bob', 'speaks' => false, 'stripes' => [], 'stripe_dir' => 'h'],
                ['type' => 'lightbulb', 'color' => '#ffd166', 'x' => 0.5, 'y' => 2, 'scale' => 1.2, 'label' => '', 'anim' => 'bob', 'speaks' => false, 'stripes' => [], 'stripe_dir' => 'h'],
            ];
        }

        return [
            'objects' => $objects,
            'bg' => [$hex($rawBg[0] ?? null, $bg[0]), $hex($rawBg[1] ?? null, $bg[1])],
            'accent' => $hex($raw['accent'] ?? null, $accent),
            'title' => mb_substr(trim((string) ($raw['title'] ?? $fallbackTitle)), 0, 28),
            'camera' => in_array($raw['camera'] ?? null, self::CAMERAS, true) ? $raw['camera'] : self::CAMERAS[$index % count(self::CAMERAS)],
            'characters' => $characters,
            'props' => $props,
        ];
    }

    private function buildSrt(ProductionRun $run): string
    {
        $scenes = $run->project->scenes()->get()->keyBy('id');
        $out = '';
        $n = 1;

        foreach ($run->artifact('timeline', []) as $item) {
            $text = trim((string) ($scenes[$item['scene_id']]->narration ?? ''));
            $sentences = preg_split('/(?<=[.?!])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $chunks = [];
            foreach ($sentences as $sentence) {
                if (mb_strlen($sentence) > 60 && str_contains($sentence, ',')) {
                    foreach (preg_split('/(?<=,)\s+/u', $sentence, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                        $chunks[] = $part;
                    }
                } else {
                    $chunks[] = $sentence;
                }
            }

            $total = max(1, array_sum(array_map('mb_strlen', $chunks)));
            $t = (float) $item['start'];
            foreach ($chunks as $chunk) {
                $d = $item['audio'] * mb_strlen($chunk) / $total;
                $out .= $n++."\n".$this->srtTime($t).' --> '.$this->srtTime($t + $d)."\n".wordwrap($chunk, 34, "\n", false)."\n\n";
                $t += $d;
            }
        }

        return $out;
    }

    private function srtTime(float $seconds): string
    {
        $ms = (int) round($seconds * 1000);

        return sprintf('%02d:%02d:%02d,%03d', intdiv($ms, 3600000), intdiv($ms, 60000) % 60, intdiv($ms, 1000) % 60, $ms % 1000);
    }

    private function dir(ProductionRun $run, ?string $sub = null): string
    {
        $root = (string) config('services.production.storage_root') ?: storage_path('app/production');
        $path = $root.DIRECTORY_SEPARATOR.'run_'.$run->id.($sub ? DIRECTORY_SEPARATOR.$sub : '');
        File::ensureDirectoryExists($path);

        return $path;
    }

    private function safeMessage(Throwable $exception): string
    {
        $message = $exception->getMessage();
        $safePrefixes = ['9Router', 'Tavily', 'ClipWize', 'Blender', 'Render', 'Riset', 'Perintah media', 'Token', 'AI ', 'Tidak ada', 'Berkas'];
        foreach ($safePrefixes as $prefix) {
            if (str_starts_with($message, $prefix)) {
                return mb_substr($message, 0, 600);
            }
        }

        return 'Tahap gagal karena galat internal. Lihat log server.';
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(ProductionRun $run, string $action, array $metadata): void
    {
        AuditLog::query()->create([
            'actor_id' => $run->requested_by,
            'action' => $action,
            'subject_type' => 'project',
            'subject_id' => $run->project_id ?? 0,
            'metadata' => $metadata + ['workspace_id' => $run->workspace_id, 'run_id' => $run->id],
        ]);
    }
}
