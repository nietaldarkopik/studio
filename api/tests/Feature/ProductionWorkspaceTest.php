<?php

namespace Tests\Feature;

use App\Jobs\GenerateScriptDraft;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\NineRouterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class ProductionWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_save_script_and_manage_ordered_scenes(): void
    {
        [$owner, $workspace, $project] = $this->projectFor('owner');
        $base = "/api/workspaces/{$workspace->id}/projects/{$project->id}";

        $this->actingAs($owner)
            ->getJson("{$base}/production")
            ->assertOk()
            ->assertJsonPath('script', null)
            ->assertJsonPath('scenes', []);

        $this->actingAs($owner)
            ->putJson("{$base}/script", [
                'format' => 'chain_build',
                'narration' => 'Kamu membangun kota di tengah gurun.',
            ])
            ->assertOk()
            ->assertJsonPath('script.word_count', 6)
            ->assertJsonPath('script.version', 1);

        $this->actingAs($owner)
            ->putJson("{$base}/script", [
                'format' => 'mystery_investigation',
                'narration' => 'Mengapa kota itu berdiri di sini?',
            ])
            ->assertOk()
            ->assertJsonPath('script.word_count', 6)
            ->assertJsonPath('script.version', 2);

        $firstScene = $this->actingAs($owner)
            ->postJson("{$base}/scenes", ['title' => 'Hook'])
            ->assertCreated()
            ->json('scene.id');
        $secondScene = $this->actingAs($owner)
            ->postJson("{$base}/scenes", ['title' => 'Revelasi', 'duration_seconds' => 8])
            ->assertCreated()
            ->json('scene.id');

        $this->actingAs($owner)
            ->putJson("{$base}/scenes/order", ['scene_ids' => [$secondScene, $firstScene]])
            ->assertOk()
            ->assertJsonPath('scenes.0.id', $secondScene)
            ->assertJsonPath('scenes.0.position', 0);

        $this->actingAs($owner)
            ->patchJson("{$base}/scenes/{$firstScene}", [
                'visual_description' => 'Diorama vertikal dengan cahaya hangat.',
                'camera_direction' => 'Slow push-in, kamera portrait 9:16.',
                'duration_seconds' => 6,
            ])
            ->assertOk()
            ->assertJsonPath('scene.visual_description', 'Diorama vertikal dengan cahaya hangat.')
            ->assertJsonPath('scene.duration_seconds', 6);

        $this->actingAs($owner)
            ->getJson("{$base}/production")
            ->assertOk()
            ->assertJsonPath('script.version', 2)
            ->assertJsonCount(2, 'scenes');
    }

    public function test_viewer_cannot_write_and_other_workspaces_cannot_read_production_data(): void
    {
        [$viewer, $workspace, $project] = $this->projectFor('viewer');
        [$otherUser, $otherWorkspace] = $this->workspaceFor('owner');
        $base = "/api/workspaces/{$workspace->id}/projects/{$project->id}";

        $this->actingAs($viewer)
            ->putJson("{$base}/script", [
                'format' => 'flag_banter',
                'narration' => 'Dua bendera membandingkan bentuk negaranya.',
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->postJson("{$base}/scenes", ['title' => 'A scene'])
            ->assertForbidden();

        $this->actingAs($otherUser)
            ->getJson("/api/workspaces/{$otherWorkspace->id}/projects/{$project->id}/production")
            ->assertNotFound();
    }

    public function test_script_format_and_scene_duration_are_validated(): void
    {
        [$owner, $workspace, $project] = $this->projectFor('owner');
        $base = "/api/workspaces/{$workspace->id}/projects/{$project->id}";

        $this->actingAs($owner)
            ->putJson("{$base}/script", [
                'format' => 'invented_format',
                'narration' => 'Draft text',
            ])
            ->assertUnprocessable();

        $this->actingAs($owner)
            ->postJson("{$base}/scenes", [
                'title' => 'Too long scene',
                'duration_seconds' => 120,
            ])
            ->assertUnprocessable();
    }

    public function test_owner_can_generate_a_reviewable_draft_without_saving_it(): void
    {
        [$owner, $workspace, $project] = $this->projectFor('owner');
        $base = "/api/workspaces/{$workspace->id}/projects/{$project->id}/script/generate";

        config([
            'services.nine_router.base_url' => 'http://localhost:20128/v1',
            'services.nine_router.api_key' => 'test-router-key',
            'services.nine_router.api_key_file' => null,
            'services.nine_router.model' => 'Kombo',
        ]);
        Http::fake([
            'http://localhost:20128/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Ini draf narasi untuk ditinjau sebelum disimpan.']],
                ],
            ]),
        ]);

        $this->actingAs($owner)
            ->postJson($base, ['format' => 'chain_build'])
            ->assertStatus(202)
            ->assertJsonPath('generation.status', 'queued');

        $this->actingAs($owner)
            ->getJson($base)
            ->assertOk()
            ->assertJsonPath('generation.status', 'completed')
            ->assertJsonPath('generation.narration', 'Ini draf narasi untuk ditinjau sebelum disimpan.')
            ->assertJsonPath('generation.model', 'Kombo');

        $this->assertDatabaseMissing('scripts', ['project_id' => $project->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'script.draft_generated',
            'subject_id' => $project->id,
        ]);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'http://localhost:20128/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer test-router-key')
            && $request['model'] === 'Kombo'
            && str_contains($request['messages'][1]['content'], $project->topic));
    }

    public function test_viewer_cannot_generate_and_missing_provider_configuration_is_safe(): void
    {
        [$viewer, $workspace, $project] = $this->projectFor('viewer');
        $base = "/api/workspaces/{$workspace->id}/projects/{$project->id}/script/generate";

        Http::fake();
        $this->actingAs($viewer)
            ->postJson($base, ['format' => 'chain_build'])
            ->assertForbidden();
        Http::assertNothingSent();

        $this->actingAs(User::factory()->create())
            ->postJson($base, ['format' => 'chain_build'])
            ->assertNotFound();

        config([
            'services.nine_router.api_key' => null,
            'services.nine_router.api_key_file' => 'missing-test-key.txt',
        ]);
        [$owner, $ownerWorkspace, $ownerProject] = $this->projectFor('owner');
        $ownerBase = "/api/workspaces/{$ownerWorkspace->id}/projects/{$ownerProject->id}/script/generate";

        $this->actingAs($owner)
            ->postJson($ownerBase, ['format' => 'chain_build'])
            ->assertStatus(202);

        $this->actingAs($owner)
            ->getJson($ownerBase)
            ->assertOk()
            ->assertJsonPath('generation.status', 'failed')
            ->assertJsonPath('generation.error_message', '9Router belum dikonfigurasi dengan lengkap.');
        Http::assertNothingSent();
    }

    public function test_generation_runs_in_the_queue_so_the_request_returns_immediately(): void
    {
        [$owner, $workspace, $project] = $this->projectFor('owner');
        $base = "/api/workspaces/{$workspace->id}/projects/{$project->id}/script/generate";

        Queue::fake();
        Http::fake();

        $response = $this->actingAs($owner)
            ->postJson($base, ['format' => 'chain_build'])
            ->assertStatus(202)
            ->assertJsonPath('generation.status', 'queued');

        Queue::assertPushed(
            GenerateScriptDraft::class,
            fn (GenerateScriptDraft $job) => $job->generationId === $response->json('generation.id'),
        );
        Http::assertNothingSent();
    }

    public function test_active_generation_blocks_duplicate_background_requests(): void
    {
        [$owner, $workspace, $project] = $this->projectFor('owner');
        $base = "/api/workspaces/{$workspace->id}/projects/{$project->id}/script/generate";

        Queue::fake();

        $this->actingAs($owner)
            ->postJson($base, ['format' => 'chain_build'])
            ->assertStatus(202);

        $this->actingAs($owner)
            ->postJson($base, ['format' => 'chain_build'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Masih ada draf AI yang sedang diproses di latar belakang.');

        Queue::assertPushed(GenerateScriptDraft::class, 1);
    }

    public function test_provider_error_message_is_shown_with_credentials_redacted(): void
    {
        [$owner, $workspace, $project] = $this->projectFor('owner');
        $base = "/api/workspaces/{$workspace->id}/projects/{$project->id}/script/generate";
        config([
            'services.nine_router.base_url' => 'http://localhost:20128/v1',
            'services.nine_router.api_key' => 'test-router-key',
            'services.nine_router.api_key_file' => null,
            'services.nine_router.model' => 'Kombo',
        ]);
        Http::fake([
            'http://localhost:20128/v1/chat/completions' => Http::response([
                'error' => [
                    'message' => 'OAuth access token has been revoked. api_key=sk-secret-value-123456789',
                ],
            ], 401),
        ]);

        $this->actingAs($owner)
            ->postJson($base, ['format' => 'chain_build'])
            ->assertStatus(202);

        $response = $this->actingAs($owner)
            ->getJson($base)
            ->assertOk()
            ->assertJsonPath('generation.status', 'failed')
            ->assertJsonPath(
                'generation.error_message',
                '9Router HTTP 401: OAuth access token has been revoked. api_key=[REDACTED]',
            );

        $this->assertStringNotContainsString('sk-secret-value', $response->getContent());
    }

    public function test_provider_timeout_returns_a_safe_actionable_error(): void
    {
        [$owner, $workspace, $project] = $this->projectFor('owner');
        $base = "/api/workspaces/{$workspace->id}/projects/{$project->id}/script/generate";
        $this->mock(NineRouterClient::class, function ($mock): void {
            $mock->shouldReceive('generateScriptDraft')
                ->once()
                ->andThrow(new RuntimeException('private upstream detail', 503));
        });

        $this->actingAs($owner)
            ->postJson($base, ['format' => 'chain_build'])
            ->assertStatus(202);

        $response = $this->actingAs($owner)
            ->getJson($base)
            ->assertOk()
            ->assertJsonPath('generation.status', 'failed')
            ->assertJsonPath('generation.error_message', 'Draf AI gagal dibuat.');

        $this->assertStringNotContainsString('private upstream detail', $response->getContent());
    }

    public function test_draft_includes_a_storyboard_breakdown_that_can_be_imported_once(): void
    {
        [$owner, $workspace, $project] = $this->projectFor('owner');
        $base = "/api/workspaces/{$workspace->id}/projects/{$project->id}";
        config([
            'services.nine_router.base_url' => 'http://localhost:20128/v1',
            'services.nine_router.api_key' => 'test-router-key',
            'services.nine_router.api_key_file' => null,
            'services.nine_router.model' => 'Kombo',
        ]);
        Http::fake([
            'http://localhost:20128/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => implode("\n", [
                            '```json',
                            json_encode([
                                'narration' => 'Kota gurun ini menyimpan panas di siang hari.',
                                'scenes' => [
                                    [
                                        'title' => 'Hook gurun',
                                        'narration' => 'Kota gurun ini menyimpan panas.',
                                        'visual_description' => 'Diorama kota di tengah gurun.',
                                        'camera_direction' => 'Slow push-in',
                                        'duration_seconds' => 5,
                                    ],
                                    [
                                        'title' => 'Revelasi',
                                        'narration' => 'Panas itu dipakai kembali saat malam.',
                                        'duration_seconds' => 90,
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
            ->postJson("{$base}/script/generate", ['format' => 'chain_build'])
            ->assertStatus(202);

        $generation = $this->actingAs($owner)
            ->getJson("{$base}/script/generate")
            ->assertOk()
            ->assertJsonPath('generation.status', 'completed')
            ->assertJsonPath('generation.scene_count', 2)
            ->assertJsonPath('generation.scenes.0.title', 'Hook gurun')
            ->assertJsonPath('generation.scenes.1.duration_seconds', 5)
            ->assertJsonPath('generation.storyboard_imported', false)
            ->json('generation');

        $this->assertDatabaseMissing('scenes', ['project_id' => $project->id]);

        $this->actingAs($owner)
            ->postJson("{$base}/storyboard/import", ['generation_id' => $generation['id']])
            ->assertCreated()
            ->assertJsonPath('scenes.0.title', 'Hook gurun')
            ->assertJsonPath('scenes.1.visual_description', null);

        $this->assertDatabaseHas('scenes', [
            'project_id' => $project->id,
            'title' => 'Revelasi',
            'duration_seconds' => 5,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'storyboard.imported',
            'subject_id' => $project->id,
        ]);

        $this->actingAs($owner)
            ->postJson("{$base}/storyboard/import", ['generation_id' => $generation['id']])
            ->assertStatus(409);
    }

    public function test_viewer_cannot_import_storyboard_or_edit_research(): void
    {
        [$viewer, $workspace, $project] = $this->projectFor('viewer');
        $base = "/api/workspaces/{$workspace->id}/projects/{$project->id}";

        $this->actingAs($viewer)
            ->postJson("{$base}/storyboard/import", ['generation_id' => 1])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->postJson("{$base}/research-sources", [
                'title' => 'Sumber',
                'url' => 'https://example.com/artikel',
            ])
            ->assertForbidden();
    }

    public function test_research_needs_two_independent_verified_sources_and_can_be_reset(): void
    {
        [$owner, $workspace, $project] = $this->projectFor('owner');
        $base = "/api/workspaces/{$workspace->id}/projects/{$project->id}";

        $this->actingAs($owner)
            ->getJson("{$base}/production")
            ->assertOk()
            ->assertJsonPath('research.verified', false)
            ->assertJsonPath('research.source_count', 0);

        $first = $this->actingAs($owner)
            ->postJson("{$base}/research-sources", [
                'title' => 'Laporan iklim',
                'url' => 'https://www.example.com/laporan',
                'publisher' => 'Lembaga Iklim',
            ])
            ->assertCreated()
            ->assertJsonPath('research.source_count', 1)
            ->assertJsonPath('research.verified', false)
            ->json('research.sources.0.id');

        $second = $this->actingAs($owner)
            ->postJson("{$base}/research-sources", [
                'title' => 'Kajian kota',
                'url' => 'https://example.com/kajian',
            ])
            ->assertCreated()
            ->json('research.sources.1.id');

        $this->actingAs($owner)
            ->patchJson("{$base}/research-sources/{$first}", ['verified' => true])
            ->assertOk()
            ->assertJsonPath('research.verified_count', 1)
            ->assertJsonPath('research.verified', false);

        $this->actingAs($owner)
            ->patchJson("{$base}/research-sources/{$second}", ['verified' => true])
            ->assertOk()
            ->assertJsonPath('research.verified_count', 2)
            ->assertJsonPath('research.independent_domain_count', 1)
            ->assertJsonPath('research.verified', false);

        $third = $this->actingAs($owner)
            ->postJson("{$base}/research-sources", [
                'title' => 'Arsip resmi',
                'url' => 'https://arsip.example.org/dokumen',
            ])
            ->assertCreated()
            ->json('research.sources.2.id');

        $this->actingAs($owner)
            ->patchJson("{$base}/research-sources/{$third}", ['verified' => true])
            ->assertOk()
            ->assertJsonPath('research.independent_domain_count', 2)
            ->assertJsonPath('research.verified', true);

        $this->actingAs($owner)
            ->patchJson("{$base}/research-sources/{$third}", ['verified' => false])
            ->assertOk()
            ->assertJsonPath('research.verified_count', 2)
            ->assertJsonPath('research.verified', false);

        $this->actingAs($owner)
            ->deleteJson("{$base}/research-sources/{$second}")
            ->assertOk()
            ->assertJsonPath('research.source_count', 2)
            ->assertJsonPath('research.verified', false);

        $this->actingAs($owner)
            ->postJson("{$base}/research-sources", ['title' => 'Tanpa URL'])
            ->assertUnprocessable();
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
