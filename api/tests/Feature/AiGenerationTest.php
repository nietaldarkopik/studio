<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiDraft;
use App\Models\AiGeneration;
use App\Models\Project;
use App\Models\Scene;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\NineRouterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class AiGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_generate_a_prompt_draft_from_a_scene_without_saving_it(): void
    {
        [$owner, $workspace, $project] = $this->projectFor('owner');
        $scene = $this->sceneFor($project);
        $base = "/api/workspaces/{$workspace->id}/projects/{$project->id}/ai/prompt";

        $this->configureProvider();
        Http::fake([
            'http://localhost:20128/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'title' => 'Wide establishing — kota gurun',
                            'positive' => 'Aerial shot of a desert city at dusk, warm rim light',
                            'negative' => 'blur, text, distortion',
                            'style' => 'cinematic documentary',
                            'camera' => '35mm wide, slow push-in',
                            'lighting' => 'golden hour rim light',
                            'mood' => 'hangat dan tegang',
                            'notes' => 'Periksa ulang arah kamera sebelum render.',
                        ]),
                    ],
                ]],
            ]),
        ]);

        $this->actingAs($owner)
            ->postJson($base, ['scene_id' => $scene->id])
            ->assertStatus(202)
            ->assertJsonPath('generation.status', 'queued')
            ->assertJsonPath('generation.task', 'prompt');

        $this->actingAs($owner)
            ->getJson($base)
            ->assertOk()
            ->assertJsonPath('generation.status', 'completed')
            ->assertJsonPath('generation.model', 'Kombo')
            ->assertJsonPath('generation.result.positive', 'Aerial shot of a desert city at dusk, warm rim light')
            ->assertJsonPath('generation.result.mood', 'hangat dan tegang');

        $this->assertDatabaseMissing('prompts', ['project_id' => $project->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ai.prompt_generated',
            'subject_id' => $project->id,
        ]);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'http://localhost:20128/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer test-router-key')
            && $request['model'] === 'Kombo'
            && str_contains($request['messages'][1]['content'], $scene->title)
            && str_contains($request['messages'][1]['content'], $project->topic));
    }

    public function test_owner_can_generate_research_findings_from_a_source_url(): void
    {
        [$owner, $workspace, $project] = $this->projectFor('owner');
        $base = "/api/workspaces/{$workspace->id}/projects/{$project->id}/ai/research";

        $this->configureProvider();
        Http::fake([
            'http://localhost:20128/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => implode("\n", [
                            '```json',
                            json_encode([
                                'key_findings' => "- Cari angka resmi suhu kota\n- Verifikasi tanggal laporan",
                                'reliability_note' => 'Sumber sekunder. Perlu dicek ke dokumen asli.',
                            ]),
                            '```',
                        ]),
                    ],
                ]],
            ]),
        ]);

        $this->actingAs($owner)
            ->postJson($base, [
                'url' => 'https://www.example.com/laporan-iklim',
                'title' => 'Laporan iklim 2024',
                'publisher' => 'Lembaga Iklim',
            ])
            ->assertStatus(202)
            ->assertJsonPath('generation.task', 'research');

        $this->actingAs($owner)
            ->getJson($base)
            ->assertOk()
            ->assertJsonPath('generation.status', 'completed')
            ->assertJsonPath('generation.result.reliability_note', 'Sumber sekunder. Perlu dicek ke dokumen asli.');

        $this->assertDatabaseMissing('research_sources', ['project_id' => $project->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ai.research_generated',
            'subject_id' => $project->id,
        ]);
        Http::assertSent(fn (HttpRequest $request) => str_contains(
            $request['messages'][1]['content'],
            'https://www.example.com/laporan-iklim',
        ));
    }

    public function test_owner_can_search_and_curate_research_sources(): void
    {
        [$owner, $workspace, $project] = $this->projectFor('owner');
        $base = "/api/workspaces/{$workspace->id}/projects/{$project->id}/ai/research_search";

        $this->configureProvider();
        $this->configureSearchProvider();

        Http::fake([
            'https://api.tavily.com/search' => Http::response([
                'results' => [
                    [
                        'url' => 'https://news.example.com/heat-city',
                        'title' => 'Kota gurun dan suhu ekstrem',
                        'content' => 'Laporan tentang suhu permukaan yang memecahkan rekor.',
                        'score' => 0.92,
                    ],
                ],
            ]),
            'http://localhost:20128/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'candidates' => [
                                [
                                    'url' => 'https://news.example.com/heat-city',
                                    'title' => 'Kota gurun dan suhu ekstrem',
                                    'publisher' => 'News Example',
                                    'published_at' => '2024-03-01',
                                    'key_findings' => "- Verifikasi angka suhu resmi\n- Cek tanggal laporan",
                                    'reliability_note' => 'Media arus utama. Perlu dicek ke dokumen asli.',
                                    'reason' => 'Relevan dengan topik panas kota.',
                                ],
                                [
                                    'url' => 'https://halusinasi.example.com/palsu',
                                    'title' => 'Sumber karangan',
                                    'key_findings' => '- Tidak nyata',
                                ],
                            ],
                        ]),
                    ],
                ]],
            ]),
        ]);

        $this->actingAs($owner)
            ->postJson($base, ['query' => 'suhu ekstrem kota gurun'])
            ->assertStatus(202)
            ->assertJsonPath('generation.task', 'research_search');

        $this->actingAs($owner)
            ->getJson($base)
            ->assertOk()
            ->assertJsonPath('generation.status', 'completed')
            ->assertJsonPath('generation.result.count', 1)
            ->assertJsonPath('generation.result.candidates.0.url', 'https://news.example.com/heat-city')
            ->assertJsonPath('generation.result.candidates.0.publisher', 'News Example');

        $this->assertDatabaseMissing('research_sources', ['project_id' => $project->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ai.research_search_generated',
            'subject_id' => $project->id,
        ]);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://api.tavily.com/search'
            && $request['query'] === 'suhu ekstrem kota gurun');
    }

    public function test_research_search_requires_a_search_provider(): void
    {
        [$owner, $workspace, $project] = $this->projectFor('owner');
        $base = "/api/workspaces/{$workspace->id}/projects/{$project->id}/ai/research_search";

        $this->configureProvider();
        config(['services.tavily.api_key' => null]);
        Http::fake(['http://localhost:20128/v1/chat/completions' => Http::response()]);

        $this->actingAs($owner)
            ->postJson($base, ['query' => 'suhu ekstrem kota'])
            ->assertStatus(202);

        $this->actingAs($owner)
            ->getJson($base)
            ->assertOk()
            ->assertJsonPath('generation.status', 'failed')
            ->assertJsonPath('generation.error_message', 'Tavily belum dikonfigurasi.');
    }

    public function test_research_fill_reads_the_page_text_when_available(): void
    {
        [$owner, $workspace, $project] = $this->projectFor('owner');
        $base = "/api/workspaces/{$workspace->id}/projects/{$project->id}/ai/research";

        $this->configureProvider();
        $this->configureSearchProvider();

        Http::fake([
            'https://api.tavily.com/extract' => Http::response([
                'results' => [[
                    'url' => 'https://www.example.com/laporan-iklim',
                    'raw_content' => 'Suhu rata-rata tahunan mencapai 48 derajat Celsius pada 2023.',
                ]],
            ]),
            'http://localhost:20128/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'title' => 'Laporan iklim 2023',
                            'publisher' => 'Lembaga Iklim',
                            'published_at' => '2023-11-20',
                            'key_findings' => '- Suhu rata-rata 48 derajat Celsius (halaman)',
                            'reliability_note' => 'Lembaga riset. Cek metodologi.',
                            'inferred_fields' => ['publisher'],
                        ]),
                    ],
                ]],
            ]),
        ]);

        $this->actingAs($owner)
            ->postJson($base, ['url' => 'https://www.example.com/laporan-iklim'])
            ->assertStatus(202);

        $this->actingAs($owner)
            ->getJson($base)
            ->assertOk()
            ->assertJsonPath('generation.status', 'completed')
            ->assertJsonPath('generation.result.published_at', '2023-11-20')
            ->assertJsonPath('generation.result.inferred_fields', ['publisher']);

        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'http://localhost:20128/v1/chat/completions'
            && str_contains($request['messages'][1]['content'], '48 derajat Celsius'));
    }

    public function test_generation_runs_in_the_queue_and_duplicate_requests_are_blocked(): void
    {
        [$owner, $workspace, $project] = $this->projectFor('owner');
        $scene = $this->sceneFor($project);
        $base = "/api/workspaces/{$workspace->id}/projects/{$project->id}/ai/prompt";

        Queue::fake();
        Http::fake();

        $response = $this->actingAs($owner)
            ->postJson($base, ['scene_id' => $scene->id])
            ->assertStatus(202);

        Queue::assertPushed(
            GenerateAiDraft::class,
            fn (GenerateAiDraft $job) => $job->generationId === $response->json('generation.id'),
        );

        $this->actingAs($owner)
            ->postJson($base, ['scene_id' => $scene->id])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Masih ada draf AI yang sedang diproses di latar belakang.');

        Queue::assertPushed(GenerateAiDraft::class, 1);
        Http::assertNothingSent();
    }

    public function test_prompt_and_research_requests_are_validated(): void
    {
        [$owner, $workspace, $project] = $this->projectFor('owner');
        [$otherUser, $otherWorkspace, $otherProject] = $this->projectFor('owner');
        $otherScene = $this->sceneFor($otherProject);

        Queue::fake();
        $base = "/api/workspaces/{$workspace->id}/projects/{$project->id}/ai";

        $this->actingAs($owner)
            ->postJson("{$base}/prompt", [])
            ->assertUnprocessable();

        $this->actingAs($owner)
            ->postJson("{$base}/prompt", ['scene_id' => $otherScene->id])
            ->assertUnprocessable();

        $this->actingAs($owner)
            ->postJson("{$base}/research", ['url' => 'not-a-url'])
            ->assertUnprocessable();

        $this->actingAs($owner)
            ->postJson("{$base}/research_search", [])
            ->assertUnprocessable();

        $this->actingAs($owner)
            ->postJson("{$base}/unknown", ['scene_id' => 1])
            ->assertNotFound();

        $this->actingAs($otherUser)
            ->getJson("/api/workspaces/{$otherWorkspace->id}/projects/{$project->id}/ai/prompt")
            ->assertNotFound();
    }

    public function test_viewer_cannot_generate(): void
    {
        [$viewer, $workspace, $project] = $this->projectFor('viewer');
        $scene = $this->sceneFor($project);

        Queue::fake();
        Http::fake();

        $this->actingAs($viewer)
            ->postJson("/api/workspaces/{$workspace->id}/projects/{$project->id}/ai/prompt", ['scene_id' => $scene->id])
            ->assertForbidden();

        Queue::assertNotPushed(GenerateAiDraft::class);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('ai_generations', ['project_id' => $project->id]);
    }

    public function test_provider_errors_stay_safe(): void
    {
        $this->mock(NineRouterClient::class, function ($mock): void {
            $mock->shouldReceive('generatePromptDraft')
                ->once()
                ->andThrow(new RuntimeException('private upstream detail', 503));
        });

        [$owner, $ownerWorkspace, $ownerProject] = $this->projectFor('owner');
        $ownerScene = $this->sceneFor($ownerProject);
        $ownerBase = "/api/workspaces/{$ownerWorkspace->id}/projects/{$ownerProject->id}/ai/prompt";

        $this->actingAs($owner)
            ->postJson($ownerBase, ['scene_id' => $ownerScene->id])
            ->assertStatus(202);

        $response = $this->actingAs($owner)
            ->getJson($ownerBase)
            ->assertOk()
            ->assertJsonPath('generation.status', 'failed')
            ->assertJsonPath('generation.error_message', 'Draf AI gagal dibuat.');

        $this->assertStringNotContainsString('private upstream detail', $response->getContent());
    }

    public function test_prompt_draft_result_is_capped_to_stored_limits(): void
    {
        [$owner, $workspace, $project] = $this->projectFor('owner');
        $scene = $this->sceneFor($project);
        $base = "/api/workspaces/{$workspace->id}/projects/{$project->id}/ai/prompt";

        $this->configureProvider();
        Http::fake([
            'http://localhost:20128/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'positive' => str_repeat('a', 5000),
                            'mood' => str_repeat('b', 300),
                        ]),
                    ],
                ]],
            ]),
        ]);

        $this->actingAs($owner)->postJson($base, ['scene_id' => $scene->id])->assertStatus(202);

        $result = $this->actingAs($owner)
            ->getJson($base)
            ->assertOk()
            ->assertJsonPath('generation.status', 'completed')
            ->json('generation.result');

        $this->assertSame(4000, mb_strlen($result['positive']));
        $this->assertSame(120, mb_strlen($result['mood']));
        $this->assertDatabaseHas('ai_generations', ['project_id' => $project->id, 'status' => 'completed']);
    }

    public function test_latest_generation_status_is_reported_even_when_no_result_exists_yet(): void
    {
        [$owner, $workspace, $project] = $this->projectFor('owner');
        $base = "/api/workspaces/{$workspace->id}/projects/{$project->id}/ai/research";

        Queue::fake();

        $this->actingAs($owner)
            ->getJson($base)
            ->assertOk()
            ->assertJsonPath('generation', null);

        AiGeneration::query()->create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'requested_by' => $owner->id,
            'task' => 'research',
            'status' => 'running',
            'input' => ['url' => 'https://example.com'],
        ]);

        $this->actingAs($owner)
            ->getJson($base)
            ->assertOk()
            ->assertJsonPath('generation.status', 'running')
            ->assertJsonPath('generation.result', []);
    }

    public function test_owner_can_fill_prompts_for_every_scene_at_once(): void
    {
        [$owner, $workspace, $project] = $this->projectFor('owner');
        $first = $this->sceneFor($project);
        $second = $this->sceneFor($project, 'Revelasi', 1);
        $base = "/api/workspaces/{$workspace->id}/projects/{$project->id}/ai/prompt_all";

        $this->configureProvider();
        Http::fake([
            'http://localhost:20128/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => implode("\n", [
                            '```json',
                            json_encode([
                                'prompts' => [
                                    [
                                        'scene_id' => $first->id,
                                        'title' => 'Wide establishing',
                                        'positive' => 'Aerial shot of a desert city, warm rim light',
                                        'style' => 'cinematic',
                                        'mood' => 'hangat',
                                    ],
                                    [
                                        'scene_id' => $second->id,
                                        'positive' => 'Close up of heat shimmering above the rooftops',
                                        'mood' => 'tegang',
                                    ],
                                    [
                                        'scene_id' => 9999,
                                        'positive' => 'Adegan yang tidak diminta',
                                    ],
                                ],
                            ]),
                            '```',
                        ]),
                    ],
                ]],
            ]),
        ]);

        $this->actingAs($owner)
            ->postJson($base, [])
            ->assertStatus(202)
            ->assertJsonPath('generation.task', 'prompt_all');

        $this->actingAs($owner)
            ->getJson($base)
            ->assertOk()
            ->assertJsonPath('generation.status', 'completed')
            ->assertJsonPath('generation.result.created', 2)
            ->assertJsonPath('generation.result.prompts.0.scene_id', $first->id)
            ->assertJsonPath('generation.result.prompts.0.mood', 'hangat');

        $this->assertDatabaseCount('prompts', 2);
        $this->assertDatabaseHas('prompts', [
            'scene_id' => $first->id,
            'status' => 'draft',
            'version' => 1,
            'positive' => 'Aerial shot of a desert city, warm rim light',
        ]);
        $this->assertDatabaseHas('prompts', ['scene_id' => $second->id, 'status' => 'draft']);
        $this->assertDatabaseHas('prompt_revisions', ['version' => 1]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ai.prompt_all_generated',
            'subject_id' => $project->id,
        ]);
        Http::assertSent(fn (HttpRequest $request) => str_contains(
            $request['messages'][1]['content'],
            "scene_id {$first->id}",
        ));
    }

    public function test_prompt_all_only_covers_scenes_without_a_prompt(): void
    {
        [$owner, $workspace, $project] = $this->projectFor('owner');
        $first = $this->sceneFor($project);
        $second = $this->sceneFor($project, 'Revelasi', 1);
        $aiBase = "/api/workspaces/{$workspace->id}/projects/{$project->id}/ai/prompt_all";

        $this->actingAs($owner)
            ->postJson("/api/workspaces/{$workspace->id}/projects/{$project->id}/prompts", [
                'scene_id' => $first->id,
                'positive' => 'Prompt yang sudah disusun manual',
            ])
            ->assertCreated();

        Queue::fake();

        $this->actingAs($owner)
            ->postJson($aiBase, [])
            ->assertStatus(202);

        $generation = AiGeneration::query()->latest('id')->firstOrFail();
        $this->assertSame([$second->id], array_column($generation->input['scenes'], 'scene_id'));

        $this->actingAs($owner)
            ->postJson($aiBase, [])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Masih ada draf AI yang sedang diproses di latar belakang.');
    }

    public function test_prompt_all_rejects_projects_without_scenes_or_with_full_coverage(): void
    {
        [$owner, $workspace, $project] = $this->projectFor('owner');
        $base = "/api/workspaces/{$workspace->id}/projects/{$project->id}/ai/prompt_all";

        Queue::fake();

        $this->actingAs($owner)
            ->postJson($base, [])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Storyboard belum memiliki adegan.');

        $scene = $this->sceneFor($project);
        $this->actingAs($owner)
            ->postJson("/api/workspaces/{$workspace->id}/projects/{$project->id}/prompts", [
                'scene_id' => $scene->id,
                'positive' => 'Prompt manual',
            ])
            ->assertCreated();

        $this->actingAs($owner)
            ->postJson($base, [])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Semua adegan sudah memiliki prompt.');

        Queue::assertNotPushed(GenerateAiDraft::class);
    }

    private function configureProvider(): void
    {
        config([
            'services.nine_router.base_url' => 'http://localhost:20128/v1',
            'services.nine_router.api_key' => 'test-router-key',
            'services.nine_router.api_key_file' => null,
            'services.nine_router.model' => 'Kombo',
        ]);
    }

    private function configureSearchProvider(): void
    {
        config([
            'services.tavily.base_url' => 'https://api.tavily.com',
            'services.tavily.api_key' => 'test-tavily-key',
            'services.tavily.timeout' => 30,
            'services.tavily.max_results' => 6,
            'services.tavily.search_depth' => 'basic',
        ]);
    }

    private function sceneFor(Project $project, string $title = 'Hook gurun', int $position = 0): Scene
    {
        return Scene::query()->create([
            'project_id' => $project->id,
            'position' => $position,
            'title' => $title,
            'narration' => 'Kota itu menyimpan panas di siang hari.',
            'visual_description' => 'Diorama kota di tengah gurun.',
            'camera_direction' => 'Slow push-in',
            'duration_seconds' => 5,
        ]);
    }

    private function projectFor(string $role): array
    {
        [$user, $workspace] = $this->workspaceFor($role);
        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'title' => 'Studio test',
            'topic' => 'How a city handles heat.',
        ]);

        return [$user, $workspace, $project];
    }

    private function workspaceFor(string $role): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => "Workspace {$user->id}",
            'slug' => "workspace-{$user->id}",
        ]);
        $workspace->members()->attach($user->id, ['role' => $role]);

        return [$user, $workspace];
    }
}
