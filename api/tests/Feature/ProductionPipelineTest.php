<?php

namespace Tests\Feature;

use App\Jobs\RunProduction;
use App\Models\Channel;
use App\Models\ProductionRun;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\NineRouterClient;
use App\Services\ClipWize\ClipWizeClient;
use App\Services\Production\BlenderRenderer;
use App\Services\Production\MediaToolkit;
use App\Services\Production\ProductionPipeline;
use App\Services\Search\SearchClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class ProductionPipelineTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $ai;

    private MockInterface $search;

    private MockInterface $clipwize;

    private MockInterface $media;

    private MockInterface $blender;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.production.storage_root' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'studio_test_production',
            'services.production.publish_poll_interval' => 0,
            'services.production.used_topics_file' => '',
            'services.production.max_posts_per_day' => 1,
            'services.clipwize.account_id' => 10,
            'services.clipwize.account_name' => 'Ternyata Begini',
        ]);

        $this->ai = Mockery::mock(NineRouterClient::class);
        $this->search = Mockery::mock(SearchClient::class);
        $this->clipwize = Mockery::mock(ClipWizeClient::class);
        $this->media = Mockery::mock(MediaToolkit::class);
        $this->blender = Mockery::mock(BlenderRenderer::class);

        $this->app->instance(NineRouterClient::class, $this->ai);
        $this->app->instance(SearchClient::class, $this->search);
        $this->app->instance(ClipWizeClient::class, $this->clipwize);
        $this->app->instance(MediaToolkit::class, $this->media);
        $this->app->instance(BlenderRenderer::class, $this->blender);

        $this->happyAi();
        $this->happyMedia();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(sys_get_temp_dir().DIRECTORY_SEPARATOR.'studio_test_production');
        parent::tearDown();
    }

    public function test_full_run_generates_idea_through_to_youtube_upload(): void
    {
        $run = $this->makeRun(['auto_publish' => true]);
        $this->happyClipWize(published: true);

        $this->pipeline()->run($run);
        $run->refresh();

        $this->assertSame('completed', $run->status, (string) $run->error_message);
        $this->assertSame('https://www.youtube.com/shorts/abc123XYZ', $run->artifact('post_url'));
        $this->assertSame('abc123XYZ', $run->artifact('youtube_video_id'));
        $this->assertSame(55, $run->artifact('post_id'));

        $project = $run->project;
        $this->assertSame('Kenapa Candi Tahan Gempa?', $project->title);
        $this->assertSame(4, $project->scenes()->count());
        $this->assertSame(4, $project->prompts()->count());
        $this->assertNotNull($project->script);
        $this->assertTrue($project->researchSources()->where('verified', true)->exists());
        $this->assertTrue($run->artifact('qc')['passed']);
        $this->assertFileExists($run->artifact('caption_file'));
        $this->assertStringContainsString('-->', file_get_contents($run->artifact('caption_file')));
    }

    public function test_run_waits_for_approval_when_auto_publish_is_off_and_never_uploads(): void
    {
        $run = $this->makeRun(['auto_publish' => false]);
        $this->clipwize->shouldReceive('importClip')->never();
        $this->clipwize->shouldReceive('publish')->never();

        $this->pipeline()->run($run);
        $run->refresh();

        $this->assertSame('awaiting_approval', $run->status);
        $this->assertSame('awaiting_approval', $run->stageState('publish')['status']);
        $this->assertNull($run->artifact('post_id'));
    }

    public function test_approval_endpoint_queues_the_run_and_resumed_run_uploads_once(): void
    {
        Queue::fake();
        $run = $this->makeRun(['auto_publish' => false]);
        $this->pipeline()->run($run);

        $user = User::query()->find($run->requested_by);
        $base = "/api/workspaces/{$run->workspace_id}/production-runs/{$run->id}";

        $this->actingAs($user)->postJson("{$base}/approve-publish")->assertStatus(202);
        Queue::assertPushed(RunProduction::class, fn ($job) => $job->runId === $run->id);

        $this->happyClipWize(published: true);
        $this->pipeline()->run($run->refresh());

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(55, $run->artifact('post_id'));

        // run yang sudah terbit tidak dapat disetujui/dipublikasikan lagi
        $this->actingAs($user)->postJson("{$base}/approve-publish")->assertStatus(409);
    }

    public function test_failed_qc_blocks_publishing(): void
    {
        $run = $this->makeRun(['auto_publish' => true]);
        $this->media->shouldReceive('meanVolume')->andReturn(-80.0);
        $this->clipwize->shouldReceive('importClip')->never();
        $this->clipwize->shouldReceive('publish')->never();

        $this->pipeline()->run($run);
        $run->refresh();

        $this->assertSame('needs_review', $run->status);
        $this->assertSame('needs_review', $run->stageState('qc')['status']);
        $this->assertStringContainsString('Narasi terdengar', (string) $run->error_message);
        $this->assertFalse($run->artifact('qc')['passed']);
        $this->assertNull($run->artifact('clip_id'));
    }

    public function test_unknown_publish_outcome_is_never_retried(): void
    {
        $run = $this->makeRun(['auto_publish' => true]);
        $this->clipwize->shouldReceive('assertAccount')->andReturn(['id' => 10]);
        $this->clipwize->shouldReceive('recentPosts')->andReturn([]);
        $this->clipwize->shouldReceive('importClip')->once()->andReturn(['id' => 77]);
        $this->clipwize->shouldReceive('publish')->once()
            ->andThrow(new RuntimeException('timeout', ClipWizeClient::UNKNOWN_OUTCOME));

        $this->pipeline()->run($run);
        $run->refresh();

        $this->assertSame('needs_review', $run->status);
        $this->assertStringContainsString('TIDAK diketahui', (string) $run->error_message);

        // melanjutkan tidak boleh memanggil publish lagi
        $this->pipeline()->run($run->refresh());
        $this->assertSame('needs_review', $run->refresh()->status);
    }

    public function test_daily_upload_cap_and_duplicate_title_block_publishing(): void
    {
        $run = $this->makeRun(['auto_publish' => true]);
        $this->clipwize->shouldReceive('assertAccount')->andReturn(['id' => 10]);
        $this->clipwize->shouldReceive('recentPosts')->andReturn([
            ['title' => 'Video lain', 'published_at' => now()->toIso8601String(), 'status' => 'published'],
        ]);
        $this->clipwize->shouldReceive('importClip')->never();
        $this->clipwize->shouldReceive('publish')->never();

        $this->pipeline()->run($run);
        $run->refresh();

        $this->assertSame('needs_review', $run->status);
        $this->assertStringContainsString('Batas 1 upload per hari', (string) $run->error_message);
    }

    public function test_research_with_a_single_independent_site_fails_the_run(): void
    {
        $this->search = Mockery::mock(SearchClient::class);
        $this->app->instance(SearchClient::class, $this->search);
        $this->search->shouldReceive('search')->andReturn([
            ['title' => 'A', 'url' => 'https://example.org/a', 'snippet' => 'x', 'published_at' => null, 'score' => 1.0],
        ]);
        $this->ai = Mockery::mock(NineRouterClient::class);
        $this->app->instance(NineRouterClient::class, $this->ai);
        $this->ai->shouldReceive('generateIdea')->andReturn($this->idea());
        $this->ai->shouldReceive('curateResearchCandidates')->andReturn(['candidates' => [
            ['title' => 'A', 'url' => 'https://example.org/a', 'publisher' => 'Org', 'published_at' => null, 'key_findings' => 'f', 'reliability_note' => null],
            ['title' => 'B', 'url' => 'https://www.example.org/b', 'publisher' => 'Org', 'published_at' => null, 'key_findings' => 'g', 'reliability_note' => null],
        ], 'model' => 'm']);
        $this->clipwize->shouldReceive('recentPosts')->andReturn([]);

        $run = $this->makeRun(['auto_publish' => true, 'require_research' => true]);
        $this->pipeline()->run($run);
        $run->refresh();

        $this->assertSame('failed', $run->status);
        $this->assertSame('failed', $run->stageState('research')['status']);
        $this->assertStringContainsString('sumber independen', (string) $run->error_message);
    }

    public function test_script_failing_fact_check_stops_for_review(): void
    {
        $this->ai = Mockery::mock(NineRouterClient::class);
        $this->app->instance(NineRouterClient::class, $this->ai);
        $this->happyAi(factCheckOk: false);
        $this->clipwize->shouldReceive('recentPosts')->andReturn([]);

        $run = $this->makeRun(['auto_publish' => true, 'require_research' => true]);
        $this->pipeline()->run($run);
        $run->refresh();

        $this->assertSame('needs_review', $run->status);
        $this->assertSame('script', $run->stage);
        $this->assertStringContainsString('Klaim X tidak didukung', (string) $run->error_message);
    }

    public function test_research_and_verification_are_optional_by_default(): void
    {
        // Riset hanya menemukan satu situs dan fact-check menolak naskah, tetapi riset tidak wajib.
        $this->ai = Mockery::mock(NineRouterClient::class);
        $this->app->instance(NineRouterClient::class, $this->ai);
        $this->happyAi(factCheckOk: false);
        $this->clipwize->shouldReceive('assertAccount')->andReturn(['id' => 10]);

        $run = $this->makeRun(['auto_publish' => false]);
        $this->pipeline()->run($run);
        $run->refresh();

        $this->assertSame('awaiting_approval', $run->status, (string) $run->error_message);
        $this->assertTrue($run->artifact('qc')['passed']);
        $this->assertStringContainsString('riset opsional', (string) $run->stageState('script')['message']);
        $this->assertFalse($run->project->researchSources()->where('verified', true)->exists());
    }

    public function test_script_is_written_without_any_sources(): void
    {
        $this->ai = Mockery::mock(NineRouterClient::class);
        $this->app->instance(NineRouterClient::class, $this->ai);
        $this->search = Mockery::mock(SearchClient::class);
        $this->app->instance(SearchClient::class, $this->search);
        $this->search->shouldReceive('search')->andReturn([]); // dipasang lebih dulu agar menang atas happyAi()
        $this->happyAi();

        $run = $this->makeRun(['auto_publish' => false]);
        $this->pipeline()->run($run);
        $run->refresh();

        $this->assertSame('awaiting_approval', $run->status, (string) $run->error_message);
        $this->assertSame(0, $run->project->researchSources()->count());
        $this->assertStringContainsString('tanpa sumber riset', (string) $run->stageState('script')['message']);
    }

    public function test_ai_can_auto_research_and_verify_sources_for_a_project(): void
    {
        $this->clipwize->shouldReceive('recentPosts')->andReturn([]);
        $user = User::factory()->create();
        $ws = Workspace::query()->create(['name' => 'W', 'slug' => 'w-ai']);
        $ws->members()->attach($user->id, ['role' => 'editor']);
        $project = Project::query()->create(['workspace_id' => $ws->id, 'created_by' => $user->id, 'title' => 'T', 'topic' => 'Klaim inti']);
        $base = "/api/workspaces/{$ws->id}/projects/{$project->id}/ai";

        $this->ai->shouldReceive('generateResearchQueries')->andReturn(['q1']);
        $this->ai->shouldReceive('verifySources')->andReturnUsing(function ($topic, $narration, $sources) {
            return [$sources[0]['id'] => ['supported' => true, 'note' => 'sesuai'], $sources[1]['id'] => ['supported' => false, 'note' => 'tidak relevan']];
        });

        $this->actingAs($user)->postJson("$base/research_verify")->assertStatus(409); // belum ada sumber

        $this->actingAs($user)->postJson("$base/research_auto")->assertStatus(202);
        $this->assertSame(2, $project->researchSources()->count());
        $this->assertSame(0, $project->researchSources()->where('verified', true)->count());

        $this->actingAs($user)->postJson("$base/research_verify")->assertStatus(202);
        $this->assertSame(1, $project->researchSources()->where('verified', true)->count());
        $this->assertStringContainsString('Ditolak AI', (string) $project->researchSources()->where('verified', false)->first()->reliability_note);
    }

    public function test_manual_project_is_built_without_regenerating_script_storyboard_or_prompts(): void
    {
        $this->ai = Mockery::mock(NineRouterClient::class);
        $this->app->instance(NineRouterClient::class, $this->ai);
        $this->ai->shouldReceive('generateRenderSpecs')->andReturn([]);
        // generateScriptDraft / generateIdea / curate tidak boleh dipanggil (tidak ada expectation => gagal bila dipanggil)

        $user = User::factory()->create();
        $ws = Workspace::query()->create(['name' => 'M', 'slug' => 'm-'.$user->id]);
        $ws->members()->attach($user->id, ['role' => 'owner']);
        $project = Project::query()->create(['workspace_id' => $ws->id, 'created_by' => $user->id, 'title' => 'Naskah Saya', 'topic' => 'Topik manual']);
        $project->script()->create(['format' => 'mystery_investigation', 'narration' => 'Naskah manual singkat.', 'word_count' => 3, 'version' => 1, 'status' => 'draft']);
        $scene = $project->scenes()->create(['position' => 1, 'title' => 'A', 'narration' => 'Narasi manual.', 'duration_seconds' => 5, 'status' => 'draft']);
        $project->prompts()->create(['scene_id' => $scene->id, 'created_by' => $user->id, 'status' => 'draft', 'version' => 1, 'positive' => 'x']);

        // proyek tanpa narasi adegan ditolak
        $empty = Project::query()->create(['workspace_id' => $ws->id, 'created_by' => $user->id, 'title' => 'Kosong', 'topic' => 't']);
        Queue::fake();
        $this->actingAs($user)->postJson("/api/workspaces/{$ws->id}/production-runs", ['project_id' => $empty->id])->assertStatus(422);
        $this->actingAs($user)->postJson("/api/workspaces/{$ws->id}/production-runs", ['project_id' => $project->id, 'auto_publish' => false])->assertStatus(202);

        $run = ProductionRun::query()->firstOrFail();
        $this->assertTrue($run->option('existing_project'));
        $this->clipwize->shouldReceive('recentPosts')->andReturn([]);
        $this->pipeline()->run($run);
        $run->refresh();

        $this->assertSame('awaiting_approval', $run->status, (string) $run->error_message);
        $this->assertSame('Naskah manual singkat.', $project->script()->first()->narration);
        $this->assertSame(1, $project->scenes()->count());
        $this->assertStringContainsString('naskah yang sudah Anda tulis', (string) $run->stageState('script')['message']);
        $this->assertTrue($run->artifact('qc')['passed']);
    }

    public function test_api_starts_a_run_and_viewers_cannot(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'W', 'slug' => 'w']);
        $workspace->members()->attach($owner->id, ['role' => 'owner']);
        $viewer = User::factory()->create();
        $workspace->members()->attach($viewer->id, ['role' => 'viewer']);
        $base = "/api/workspaces/{$workspace->id}/production-runs";

        $this->actingAs($viewer)->postJson($base, [])->assertForbidden();

        $this->actingAs($owner)
            ->postJson($base, ['theme' => 'sains Indonesia', 'format' => 'flag_banter'])
            ->assertStatus(202)
            ->assertJsonPath('run.status', 'queued')
            ->assertJsonPath('run.stages.0.key', 'ideate');

        Queue::assertPushed(RunProduction::class, 1);

        // hanya satu run aktif per workspace
        $this->actingAs($owner)->postJson($base, [])->assertStatus(409);
        $this->actingAs($owner)->getJson($base)->assertOk()->assertJsonCount(1, 'runs');
    }

    // ----------------------------------------------------------------- helpers

    private function pipeline(): ProductionPipeline
    {
        return $this->app->make(ProductionPipeline::class);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function makeRun(array $options): ProductionRun
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Studio', 'slug' => 'studio-'.$user->id]);
        $workspace->members()->attach($user->id, ['role' => 'owner']);
        $channel = Channel::query()->create(['workspace_id' => $workspace->id, 'name' => 'Ternyata Begini', 'platform' => 'youtube', 'external_id' => '10']);

        return ProductionRun::query()->create([
            'workspace_id' => $workspace->id,
            'channel_id' => $channel->id,
            'requested_by' => $user->id,
            'status' => 'queued',
            'options' => $options,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function idea(): array
    {
        return [
            'theme' => 'Arsitektur kuno',
            'topic' => 'Candi memakai sistem kunci batu tanpa semen yang meredam gempa.',
            'title' => 'Kenapa Candi Tahan Gempa?',
            'description' => 'Rahasia teknik kunci batu.',
            'hashtags' => ['candi', 'sejarah'],
            'queries' => ['candi interlocking stone earthquake', 'borobudur seismic'],
            'model' => 'Kombo',
        ];
    }

    private function happyAi(bool $factCheckOk = true): void
    {
        $words = implode(' ', array_fill(0, 120, 'kata'));
        $scenes = [];
        foreach (range(1, 4) as $i) {
            $scenes[] = [
                'title' => "Adegan {$i}",
                'narration' => "Ini narasi adegan {$i}. Kalimat kedua, lebih panjang agar caption terbagi dengan baik di layar.",
                'visual_description' => 'Candi di senja',
                'camera_direction' => 'push in',
                'duration_seconds' => 8,
            ];
        }

        $this->ai->shouldReceive('generateIdea')->andReturn($this->idea());
        $this->ai->shouldReceive('curateResearchCandidates')->andReturn(['candidates' => [
            ['title' => 'Paper', 'url' => 'https://journal.example.com/a', 'publisher' => 'Journal', 'published_at' => null, 'key_findings' => 'Batu saling mengunci.', 'reliability_note' => null],
            ['title' => 'Berita', 'url' => 'https://www.news-site.org/b', 'publisher' => 'News', 'published_at' => null, 'key_findings' => 'Candi tahan gempa.', 'reliability_note' => null],
        ], 'model' => 'Kombo']);
        $this->ai->shouldReceive('generateScriptDraft')->andReturn([
            'narration' => $words, 'scenes' => $scenes, 'model' => 'Kombo', 'word_count' => 120,
        ]);
        $this->ai->shouldReceive('factCheckScript')->andReturn(
            $factCheckOk
                ? ['supported' => true, 'issues' => [], 'model' => 'Kombo']
                : ['supported' => false, 'issues' => ['Klaim X tidak didukung sumber'], 'model' => 'Kombo'],
        );
        $this->ai->shouldReceive('generatePromptBatchDrafts')->andReturnUsing(function (array $ctx) {
            return ['model' => 'Kombo', 'prompts' => array_map(fn ($s) => [
                'scene_id' => $s['scene_id'], 'title' => 't', 'positive' => 'cinematic temple', 'negative' => null,
                'style' => null, 'camera' => null, 'lighting' => null, 'mood' => null, 'notes' => null,
            ], $ctx['scenes'])];
        });
        $this->ai->shouldReceive('generateRenderSpecs')->andReturn([]);

        $this->search->shouldReceive('search')->andReturn([
            ['title' => 'Paper', 'url' => 'https://journal.example.com/a', 'snippet' => 's', 'published_at' => null, 'score' => 1.0],
        ]);
        $this->clipwize->shouldReceive('recentPosts')->andReturn([])->byDefault();
        $this->clipwize->shouldReceive('tts')->andReturn([
            'bytes' => 'fake-mp3', 'duration_seconds' => 8.0, 'characters' => 50, 'format' => 'mp3',
        ]);
    }

    private function happyMedia(): void
    {
        $this->media->shouldReceive('duration')->andReturn(8.0);
        $this->media->shouldReceive('sceneClip')->andReturnUsing(fn ($d, $f, $dur, $out) => touch($out));
        $this->media->shouldReceive('concatVideos')->andReturnUsing(fn ($clips, $out) => touch($out));
        $this->media->shouldReceive('narrationTrack')->andReturnUsing(fn ($a, $d, $out) => touch($out));
        $this->media->shouldReceive('finalize')->andReturnUsing(fn ($v, $n, $out) => touch($out));
        $this->media->shouldReceive('probe')->andReturn([
            'streams' => [
                ['codec_type' => 'video', 'width' => 1080, 'height' => 1920],
                ['codec_type' => 'audio'],
            ],
            'format' => ['duration' => '33.4'],
        ]);
        $this->media->shouldReceive('meanVolume')->andReturn(-20.0)->byDefault();
        $this->media->shouldReceive('brightnessStats')->andReturn(['min' => 20.0, 'max' => 60.0, 'mean' => 40.0]);
        $this->media->shouldReceive('contactSheet')->andReturnUsing(fn ($v, $out) => touch($out));

        $this->blender->shouldReceive('render')->andReturnUsing(function (array $spec, string $specPath): void {
            foreach ($spec['scenes'] as $scene) {
                $dir = $spec['out_dir'].DIRECTORY_SEPARATOR.sprintf('scene_%02d', $scene['index']);
                File::ensureDirectoryExists($dir);
                foreach (range(1, $scene['frames']) as $n) {
                    touch($dir.DIRECTORY_SEPARATOR.sprintf('%04d.png', $n));
                }
            }
        });
        $this->blender->shouldReceive('countFrames')->andReturnUsing(
            fn (string $dir) => count(glob($dir.DIRECTORY_SEPARATOR.'*.png') ?: []),
        );
    }

    private function happyClipWize(bool $published): void
    {
        $this->clipwize->shouldReceive('assertAccount')->with(10, 'Ternyata Begini')->andReturn(['id' => 10]);
        $this->clipwize->shouldReceive('importClip')->once()->andReturn(['id' => 77]);
        $this->clipwize->shouldReceive('publish')->once()->with(77, 10)->andReturn(['id' => 55, 'status' => 'publishing']);
        $this->clipwize->shouldReceive('postStatus')->with(55)->andReturn([
            'id' => 55,
            'status' => $published ? 'published' : 'failed',
            'post_url' => 'https://www.youtube.com/shorts/abc123XYZ',
        ]);
    }
}
