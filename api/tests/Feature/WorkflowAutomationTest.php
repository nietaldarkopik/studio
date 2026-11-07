<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WorkflowAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_run_a_workflow_with_dependencies_and_an_approval_gate(): void
    {
        [$owner, $workspace, $project] = $this->projectFor('owner');
        $base = "/api/workspaces/{$workspace->id}/projects/{$project->id}";

        $workflowId = $this->actingAs($owner)
            ->postJson("{$base}/workflows", [
                'name' => 'Pipeline produksi',
                'trigger' => 'manual',
                'steps' => [
                    ['key' => 'research', 'name' => 'Riset topik'],
                    ['key' => 'script', 'name' => 'Tulis naskah', 'depends_on' => ['research']],
                    ['key' => 'publish', 'name' => 'Publikasi', 'depends_on' => ['script'], 'requires_approval' => true],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('workflow.name', 'Pipeline produksi')
            ->assertJsonPath('workflow.step_count', 3)
            ->assertJsonPath('workflow.completed_count', 0)
            ->assertJsonPath('workflow.steps.0.status', 'ready')
            ->assertJsonPath('workflow.steps.1.status', 'blocked')
            ->assertJsonPath('workflow.steps.2.status', 'blocked')
            ->json('workflow.id');

        $this->assertDatabaseHas('audit_logs', ['action' => 'workflow.created', 'subject_id' => $workflowId]);

        $steps = $this->actingAs($owner)
            ->getJson("{$base}/workflows/{$workflowId}")
            ->assertOk()
            ->json('workflow.steps');

        $research = $steps[0]['id'];
        $script = $steps[1]['id'];
        $publish = $steps[2]['id'];

        // Research runs and completes, unblocking the script step.
        $this->transition($owner, $base, $workflowId, $research, 'start')->assertOk();
        $this->transition($owner, $base, $workflowId, $research, 'complete')
            ->assertOk()
            ->assertJsonPath('workflow.steps.0.status', 'completed')
            ->assertJsonPath('workflow.steps.1.status', 'ready')
            ->assertJsonPath('workflow.steps.2.status', 'blocked')
            ->assertJsonPath('workflow.completed_count', 1);

        // A blocked step cannot start early.
        $this->transition($owner, $base, $workflowId, $publish, 'start')->assertStatus(409);

        // Script runs and completes.
        $this->transition($owner, $base, $workflowId, $script, 'start')->assertOk();
        $this->transition($owner, $base, $workflowId, $script, 'complete')
            ->assertOk()
            ->assertJsonPath('workflow.steps.2.status', 'ready');

        // The publish step requires approval before it can complete.
        $this->transition($owner, $base, $workflowId, $publish, 'start')->assertOk();
        $this->transition($owner, $base, $workflowId, $publish, 'complete')->assertStatus(409);
        $this->transition($owner, $base, $workflowId, $publish, 'approve')
            ->assertOk()
            ->assertJsonPath('workflow.steps.2.approved', true);
        $this->transition($owner, $base, $workflowId, $publish, 'complete')
            ->assertOk()
            ->assertJsonPath('workflow.steps.2.status', 'completed')
            ->assertJsonPath('workflow.completed_count', 3);
    }

    public function test_workflow_steps_can_be_added_updated_and_removed(): void
    {
        [$owner, $workspace, $project] = $this->projectFor('owner');
        $base = "/api/workspaces/{$workspace->id}/projects/{$project->id}";

        $workflowId = $this->actingAs($owner)
            ->postJson("{$base}/workflows", ['name' => 'Kosong'])
            ->assertCreated()
            ->assertJsonPath('workflow.step_count', 0)
            ->json('workflow.id');

        $stepId = $this->actingAs($owner)
            ->postJson("{$base}/workflows/{$workflowId}/steps", [
                'key' => 'qa',
                'name' => 'Quality control',
                'requires_approval' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('step.status', 'ready')
            ->json('step.id');

        $this->assertDatabaseHas('audit_logs', ['action' => 'workflow.step_added']);

        // Dependencies must reference keys inside the same workflow.
        $this->actingAs($owner)
            ->postJson("{$base}/workflows/{$workflowId}/steps", [
                'key' => 'render',
                'name' => 'Render Blender',
                'depends_on' => ['missing'],
            ])
            ->assertUnprocessable();

        // Duplicate keys are rejected.
        $this->actingAs($owner)
            ->postJson("{$base}/workflows/{$workflowId}/steps", ['key' => 'qa', 'name' => 'Duplikat'])
            ->assertUnprocessable();

        $this->actingAs($owner)
            ->patchJson("{$base}/workflows/{$workflowId}/steps/{$stepId}", ['name' => 'QC final'])
            ->assertOk()
            ->assertJsonPath('step.name', 'QC final');

        $this->actingAs($owner)
            ->deleteJson("{$base}/workflows/{$workflowId}/steps/{$stepId}")
            ->assertOk();
        $this->assertDatabaseMissing('workflow_steps', ['id' => $stepId]);

        $this->actingAs($owner)
            ->deleteJson("{$base}/workflows/{$workflowId}")
            ->assertOk();
        $this->assertDatabaseMissing('workflows', ['id' => $workflowId]);
    }

    public function test_viewer_cannot_write_workflows_and_other_workspaces_cannot_read_them(): void
    {
        [$viewer, $workspace, $project] = $this->projectFor('viewer');
        $base = "/api/workspaces/{$workspace->id}/projects/{$project->id}";

        $this->actingAs($viewer)
            ->postJson("{$base}/workflows", ['name' => 'Nope'])
            ->assertForbidden();

        $workflow = $project->workflows()->create([
            'created_by' => $viewer->id,
            'name' => 'Existing',
            'status' => 'draft',
            'trigger' => 'manual',
        ]);

        $this->actingAs($viewer)
            ->getJson("{$base}/workflows/{$workflow->id}")
            ->assertOk()
            ->assertJsonPath('workflow.name', 'Existing');

        [$otherUser, $otherWorkspace] = $this->workspaceFor('owner');
        $this->actingAs($otherUser)
            ->getJson("/api/workspaces/{$otherWorkspace->id}/projects/{$project->id}/workflows")
            ->assertNotFound();
    }

    private function transition(User $user, string $base, int $workflowId, int $stepId, string $action): TestResponse
    {
        return $this->actingAs($user)->postJson(
            "{$base}/workflows/{$workflowId}/steps/{$stepId}/transition",
            ['action' => $action],
        );
    }

    private function projectFor(string $role): array
    {
        [$user, $workspace] = $this->workspaceFor($role);
        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'title' => 'Studio workflow test',
            'topic' => 'Memetakan pipeline produksi.',
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
