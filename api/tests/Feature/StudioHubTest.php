<?php

namespace Tests\Feature;

use App\Models\ProductionRun;
use App\Models\Project;
use App\Models\ResearchSource;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class StudioHubTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'studio_hub_test';
        File::ensureDirectoryExists($this->root);
        config(['services.production.storage_root' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_sections_return_data_and_files_are_served_only_from_run_artifacts(): void
    {
        $user = User::factory()->create();
        $ws = Workspace::query()->create(['name' => 'W', 'slug' => 'w']);
        $ws->members()->attach($user->id, ['role' => 'editor']);
        $project = Project::query()->create(['workspace_id' => $ws->id, 'created_by' => $user->id, 'title' => 'Judul', 'topic' => 't']);
        ResearchSource::query()->create(['project_id' => $project->id, 'created_by' => $user->id, 'title' => 'S', 'url' => 'https://www.contoh.org/a', 'verified' => true]);

        $dir = $this->root.DIRECTORY_SEPARATOR.'run_1';
        File::ensureDirectoryExists($dir);
        file_put_contents($dir.DIRECTORY_SEPARATOR.'final.mp4', 'video');
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'rahasia.txt', 'secret');

        $run = ProductionRun::query()->create([
            'workspace_id' => $ws->id, 'project_id' => $project->id, 'requested_by' => $user->id, 'status' => 'completed',
            'artifacts' => [
                'final_video' => $dir.DIRECTORY_SEPARATOR.'final.mp4',
                'contact_sheet' => $this->root.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'bukan-folder-run.png',
                'qc' => ['passed' => true, 'checks' => [['name' => 'x', 'ok' => true, 'detail' => '']]],
            ],
        ]);
        $this->assertSame(1, $run->id);

        $base = "/api/workspaces/{$ws->id}";
        $this->actingAs($user)->getJson("$base/hub/research")->assertOk()
            ->assertJsonPath('totals.verified', 1)->assertJsonPath('sources.0.host', 'contoh.org');
        $this->actingAs($user)->getJson("$base/hub/qc")->assertOk()->assertJsonPath('reports.0.passed', true);
        $this->actingAs($user)->getJson("$base/hub/media")->assertOk()->assertJsonCount(1, 'items');
        foreach (['scenes', 'renders', 'audio', 'publishing', 'agents'] as $section) {
            $this->actingAs($user)->getJson("$base/hub/$section")->assertOk();
        }

        $this->actingAs($user)->get("$base/production-runs/1/files/video")->assertOk()->assertHeader('Content-Type', 'video/mp4');
        // path di luar folder produksi ditolak walau tercatat di artifact
        $this->actingAs($user)->get("$base/production-runs/1/files/contact")->assertNotFound();
        $this->actingAs($user)->getJson("$base/hub/passwd")->assertNotFound();

        // pengguna di luar workspace tidak dapat mengakses
        $other = User::factory()->create();
        $this->actingAs($other)->getJson("$base/hub/qc")->assertNotFound();
        $this->actingAs($other)->get("$base/production-runs/1/files/video")->assertNotFound();
    }
}
