<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_manage_workspace_profile_channels_and_members(): void
    {
        [$owner, $workspace] = $this->workspaceFor('owner');
        $crew = User::factory()->create(['email' => 'crew@example.com']);
        $base = "/api/workspaces/{$workspace->id}";

        $this->actingAs($owner)
            ->patchJson($base, ['name' => 'Studio Baru', 'timezone' => 'Asia/Makassar'])
            ->assertOk()
            ->assertJsonPath('workspace.name', 'Studio Baru')
            ->assertJsonPath('workspace.timezone', 'Asia/Makassar');

        $channelId = $this->actingAs($owner)
            ->postJson("{$base}/channels", [
                'name' => 'Kanal Utama',
                'platform' => 'youtube',
                'external_id' => 'UC-123',
            ])
            ->assertCreated()
            ->assertJsonPath('channel.platform', 'youtube')
            ->assertJsonPath('channel.is_active', true)
            ->json('channel.id');

        $this->actingAs($owner)
            ->postJson("{$base}/channels", ['name' => 'Kanal Utama'])
            ->assertUnprocessable();

        $this->actingAs($owner)
            ->patchJson("{$base}/channels/{$channelId}", ['is_active' => false, 'platform' => 'tiktok'])
            ->assertOk()
            ->assertJsonPath('channel.is_active', false)
            ->assertJsonPath('channel.platform', 'tiktok');

        $this->actingAs($owner)
            ->getJson("{$base}/channels")
            ->assertOk()
            ->assertJsonCount(1, 'channels');

        $this->actingAs($owner)
            ->postJson("{$base}/members", ['email' => $crew->email, 'role' => 'editor'])
            ->assertCreated()
            ->assertJsonPath('member.role', 'editor');

        $this->actingAs($owner)
            ->postJson("{$base}/members", ['email' => $crew->email, 'role' => 'viewer'])
            ->assertStatus(409);

        $this->actingAs($owner)
            ->postJson("{$base}/members", ['email' => 'missing@example.com', 'role' => 'viewer'])
            ->assertNotFound();

        $this->actingAs($owner)
            ->patchJson("{$base}/members/{$crew->id}", ['role' => 'viewer'])
            ->assertOk()
            ->assertJsonPath('member.role', 'viewer');

        $this->actingAs($owner)
            ->getJson("{$base}/members")
            ->assertOk()
            ->assertJsonCount(2, 'members');

        $this->actingAs($owner)
            ->deleteJson("{$base}/members/{$crew->id}")
            ->assertOk();
        $this->assertDatabaseMissing('workspace_members', ['workspace_id' => $workspace->id, 'user_id' => $crew->id]);

        $this->actingAs($owner)
            ->deleteJson("{$base}/channels/{$channelId}")
            ->assertOk();
        $this->assertDatabaseMissing('channels', ['id' => $channelId]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'channel.created', 'subject_id' => $channelId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.added', 'subject_id' => $crew->id]);
    }

    public function test_only_owners_manage_settings_and_viewers_cannot_write_channels(): void
    {
        [$editor, $workspace] = $this->workspaceFor('editor');
        $base = "/api/workspaces/{$workspace->id}";

        $this->actingAs($editor)
            ->patchJson($base, ['name' => 'Nope'])
            ->assertForbidden();

        $this->actingAs($editor)
            ->postJson("{$base}/members", ['email' => $editor->email, 'role' => 'editor'])
            ->assertForbidden();

        $this->actingAs($editor)
            ->postJson("{$base}/channels", ['name' => 'Kanal Editor'])
            ->assertCreated();

        [$viewer, $viewerWorkspace] = $this->workspaceFor('viewer');
        $viewerBase = "/api/workspaces/{$viewerWorkspace->id}";

        $this->actingAs($viewer)
            ->postJson("{$viewerBase}/channels", ['name' => 'Kanal Viewer'])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->getJson("{$viewerBase}/members")
            ->assertOk()
            ->assertJsonCount(1, 'members');

        [$outsider, $otherWorkspace] = $this->workspaceFor('owner');
        $this->actingAs($outsider)
            ->getJson("/api/workspaces/{$otherWorkspace->id}/channels")
            ->assertOk();

        $this->actingAs($outsider)
            ->getJson("/api/workspaces/{$workspace->id}/channels")
            ->assertNotFound();
    }

    public function test_last_owner_cannot_be_demoted_or_removed(): void
    {
        [$owner, $workspace] = $this->workspaceFor('owner');
        $base = "/api/workspaces/{$workspace->id}";

        $this->actingAs($owner)
            ->patchJson("{$base}/members/{$owner->id}", ['role' => 'editor'])
            ->assertStatus(422);

        $this->actingAs($owner)
            ->deleteJson("{$base}/members/{$owner->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $workspace->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
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
