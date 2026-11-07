<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudioApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_issues_a_token_and_me_returns_only_owned_workspaces(): void
    {
        $user = User::factory()->create(['password' => 'a-long-test-password']);
        $workspace = $this->workspaceFor($user, 'owner');

        $login = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'a-long-test-password',
        ])->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);

        $this->withToken($login->json('token'))
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('workspaces.0.id', $workspace->id)
            ->assertJsonPath('workspaces.0.role', 'owner');

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'actor_id' => $user->id]);
    }

    public function test_private_api_requires_authentication(): void
    {
        $this->get('/api/me')->assertUnauthorized();
    }

    public function test_projects_are_scoped_to_workspace_and_client_id_is_idempotent(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'owner');
        $otherUser = User::factory()->create();
        $otherWorkspace = $this->workspaceFor($otherUser, 'owner');

        $payload = [
            'client_id' => 'd3e5c767-8e26-4bc2-9d42-947881126c2c',
            'title' => 'Kenapa langit biru?',
            'topic' => 'Menjelaskan hamburan cahaya.',
        ];

        $created = $this->actingAs($owner)
            ->postJson("/api/workspaces/{$workspace->id}/projects", $payload)
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($owner)
            ->postJson("/api/workspaces/{$workspace->id}/projects", $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $created);

        $this->assertSame(1, Project::query()->where('workspace_id', $workspace->id)->count());
        $this->actingAs($otherUser)
            ->getJson("/api/workspaces/{$workspace->id}/projects")
            ->assertNotFound();
        $this->actingAs($otherUser)
            ->getJson("/api/workspaces/{$otherWorkspace->id}/projects")
            ->assertOk();
    }

    public function test_viewer_cannot_create_projects_or_use_another_workspaces_channel(): void
    {
        $viewer = User::factory()->create();
        $workspace = $this->workspaceFor($viewer, 'viewer');
        $owner = User::factory()->create();
        $ownerWorkspace = $this->workspaceFor($owner, 'owner');
        $anotherWorkspace = Workspace::query()->create([
            'name' => 'Other workspace',
            'slug' => 'other-workspace',
        ]);
        $foreignChannel = Channel::query()->create([
            'workspace_id' => $anotherWorkspace->id,
            'name' => 'Foreign channel',
        ]);

        $this->actingAs($owner)
            ->postJson("/api/workspaces/{$ownerWorkspace->id}/projects", [
                'title' => 'A project',
                'topic' => 'A topic',
                'channel_id' => $foreignChannel->id,
            ])
            ->assertUnprocessable();

        $this->actingAs($viewer)
            ->postJson("/api/workspaces/{$workspace->id}/projects", [
                'title' => 'A project',
                'topic' => 'A topic',
            ])
            ->assertForbidden();
    }

    public function test_project_creation_is_audited(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'owner');

        $this->actingAs($owner)
            ->postJson("/api/workspaces/{$workspace->id}/projects", [
                'title' => 'Test project',
                'topic' => 'Test topic',
            ])
            ->assertCreated();

        $project = Project::query()->firstOrFail();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'project.created',
            'actor_id' => $owner->id,
            'subject_id' => $project->id,
        ]);
        $this->assertSame(1, AuditLog::query()->where('action', 'project.created')->count());
    }

    private function workspaceFor(User $user, string $role): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => "Workspace {$user->id}",
            'slug' => "workspace-{$user->id}",
        ]);
        $workspace->members()->attach($user->id, ['role' => $role]);

        return $workspace;
    }
}
