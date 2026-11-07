<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Scene;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromptBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_build_a_structured_prompt_with_versioned_revisions(): void
    {
        [$owner, $workspace, $project] = $this->projectFor('owner');
        $scene = $this->sceneFor($project, 'Hook gurun');
        $base = "/api/workspaces/{$workspace->id}/projects/{$project->id}";

        $this->actingAs($owner)
            ->getJson("{$base}/prompts")
            ->assertOk()
            ->assertJsonPath('prompts', []);

        $promptId = $this->actingAs($owner)
            ->postJson("{$base}/prompts", [
                'scene_id' => $scene->id,
                'title' => 'Diorama gurun',
                'positive' => 'Isometric desert diorama, golden hour, ultra detailed',
                'negative' => 'blurry, low quality',
                'style' => 'isometric 3d diorama',
                'camera' => 'slow push-in, 9:16',
                'lighting' => 'warm golden hour',
                'mood' => 'mysterious',
            ])
            ->assertCreated()
            ->assertJsonPath('prompt.version', 1)
            ->assertJsonPath('prompt.status', 'draft')
            ->assertJsonPath('prompt.revision_count', 1)
            ->json('prompt.id');

        $this->assertDatabaseHas('audit_logs', ['action' => 'prompt.created', 'subject_id' => $promptId]);

        $this->actingAs($owner)
            ->patchJson("{$base}/prompts/{$promptId}", [
                'positive' => 'Isometric desert diorama at night, bioluminescent city',
            ])
            ->assertOk()
            ->assertJsonPath('prompt.version', 2)
            ->assertJsonPath('prompt.revision_count', 2);

        $this->actingAs($owner)
            ->patchJson("{$base}/prompts/{$promptId}", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('prompt.status', 'approved')
            ->assertJsonPath('prompt.version', 2)
            ->assertJsonPath('prompt.revision_count', 2);

        $this->actingAs($owner)
            ->getJson("{$base}/prompts/{$promptId}/revisions")
            ->assertOk()
            ->assertJsonCount(2, 'revisions')
            ->assertJsonPath('revisions.0.version', 2)
            ->assertJsonPath('revisions.1.snapshot.positive', 'Isometric desert diorama, golden hour, ultra detailed');

        $this->actingAs($owner)
            ->getJson("{$base}/prompts")
            ->assertOk()
            ->assertJsonPath('prompts.0.scene_id', $scene->id)
            ->assertJsonPath('prompts.0.scene_title', 'Hook gurun');

        $this->actingAs($owner)
            ->deleteJson("{$base}/prompts/{$promptId}")
            ->assertOk();
        $this->assertDatabaseMissing('prompts', ['id' => $promptId]);
    }

    public function test_prompt_requires_a_scene_from_the_same_project_and_is_unique_per_scene(): void
    {
        [$owner, $workspace, $project] = $this->projectFor('owner');
        $scene = $this->sceneFor($project, 'Hook gurun');
        $otherProject = Project::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $owner->id,
            'title' => 'Other project',
            'topic' => 'Other topic',
        ]);
        $foreignScene = $this->sceneFor($otherProject, 'Foreign scene');
        $base = "/api/workspaces/{$workspace->id}/projects/{$project->id}";

        $this->actingAs($owner)
            ->postJson("{$base}/prompts", ['scene_id' => $foreignScene->id, 'positive' => 'test'])
            ->assertUnprocessable();

        $this->actingAs($owner)
            ->postJson("{$base}/prompts", ['scene_id' => $scene->id])
            ->assertUnprocessable();

        $this->actingAs($owner)
            ->postJson("{$base}/prompts", ['scene_id' => $scene->id, 'positive' => 'first prompt'])
            ->assertCreated();

        $this->actingAs($owner)
            ->postJson("{$base}/prompts", ['scene_id' => $scene->id, 'positive' => 'duplicate prompt'])
            ->assertStatus(409);
    }

    public function test_viewer_cannot_write_prompts_and_other_workspaces_cannot_read_them(): void
    {
        [$viewer, $workspace, $project] = $this->projectFor('viewer');
        $scene = $this->sceneFor($project, 'Hook gurun');
        [$otherUser, $otherWorkspace] = $this->workspaceFor('owner');
        $base = "/api/workspaces/{$workspace->id}/projects/{$project->id}";

        $this->actingAs($viewer)
            ->postJson("{$base}/prompts", ['scene_id' => $scene->id, 'positive' => 'nope'])
            ->assertForbidden();

        $this->actingAs($otherUser)
            ->getJson("/api/workspaces/{$otherWorkspace->id}/projects/{$project->id}/prompts")
            ->assertNotFound();
    }

    private function sceneFor(Project $project, string $title): Scene
    {
        return $project->scenes()->create([
            'position' => (int) $project->scenes()->max('position') + 1,
            'title' => $title,
            'duration_seconds' => 5,
            'status' => 'draft',
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
