<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WorkspaceSettingsController extends Controller
{
    private const ROLES = ['owner', 'editor', 'viewer'];

    public function update(Request $request, int $workspaceId): JsonResponse
    {
        $workspace = $this->authorizedWorkspace($request, $workspaceId);
        $this->ensureOwner($workspace);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'timezone' => ['sometimes', 'string', 'max:64'],
        ]);

        DB::transaction(function () use ($request, $workspace, $validated): void {
            $dirty = $workspace->fill($validated)->getDirty();
            unset($dirty['updated_at']);

            if ($dirty === []) {
                return;
            }

            $workspace->save();

            $this->audit($request, $workspace, 'workspace.updated', $workspace->id, [
                'fields' => array_keys($dirty),
            ]);
        });

        return response()->json(['workspace' => $this->workspacePayload($workspace->refresh())]);
    }

    public function channels(Request $request, int $workspaceId): JsonResponse
    {
        $workspace = $this->authorizedWorkspace($request, $workspaceId);

        return response()->json([
            'channels' => $workspace->channels()->orderBy('name')->get()->map(fn (Channel $channel) => $this->channelPayload($channel))->all(),
        ]);
    }

    public function storeChannel(Request $request, int $workspaceId): JsonResponse
    {
        $workspace = $this->authorizedWorkspace($request, $workspaceId);
        $this->ensureCanEdit($workspace);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('channels', 'name')->where(fn ($query) => $query->where('workspace_id', $workspace->id)),
            ],
            'platform' => ['sometimes', Rule::in(['youtube', 'tiktok', 'instagram', 'facebook'])],
            'external_id' => ['sometimes', 'nullable', 'string', 'max:191'],
            'is_active' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'nullable', 'array'],
        ]);

        $channel = DB::transaction(function () use ($request, $workspace, $validated) {
            $channel = $workspace->channels()->create([
                'name' => trim($validated['name']),
                'platform' => $validated['platform'] ?? 'youtube',
                'external_id' => $validated['external_id'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
                'settings' => $validated['settings'] ?? null,
            ]);

            $this->audit($request, $workspace, 'channel.created', $channel->id, [
                'name' => $channel->name,
                'platform' => $channel->platform,
            ]);

            return $channel;
        });

        return response()->json(['channel' => $this->channelPayload($channel)], 201);
    }

    public function updateChannel(Request $request, int $workspaceId, int $channelId): JsonResponse
    {
        $workspace = $this->authorizedWorkspace($request, $workspaceId);
        $this->ensureCanEdit($workspace);
        $channel = $workspace->channels()->whereKey($channelId)->firstOrFail();

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:120',
                Rule::unique('channels', 'name')
                    ->where(fn ($query) => $query->where('workspace_id', $workspace->id))
                    ->ignore($channel->id),
            ],
            'platform' => ['sometimes', Rule::in(['youtube', 'tiktok', 'instagram', 'facebook'])],
            'external_id' => ['sometimes', 'nullable', 'string', 'max:191'],
            'is_active' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'nullable', 'array'],
        ]);

        DB::transaction(function () use ($request, $workspace, $channel, $validated): void {
            $dirty = $channel->fill($validated)->getDirty();
            unset($dirty['updated_at']);

            if ($dirty === []) {
                return;
            }

            if (isset($dirty['name'])) {
                $channel->name = trim($channel->name);
            }

            $channel->save();

            $this->audit($request, $workspace, 'channel.updated', $channel->id, [
                'fields' => array_keys($dirty),
            ]);
        });

        return response()->json(['channel' => $this->channelPayload($channel->refresh())]);
    }

    public function deleteChannel(Request $request, int $workspaceId, int $channelId): JsonResponse
    {
        $workspace = $this->authorizedWorkspace($request, $workspaceId);
        $this->ensureOwner($workspace);
        $channel = $workspace->channels()->whereKey($channelId)->firstOrFail();

        DB::transaction(function () use ($request, $workspace, $channel): void {
            $this->audit($request, $workspace, 'channel.removed', $channel->id, [
                'name' => $channel->name,
            ]);

            $channel->delete();
        });

        return response()->json(['message' => 'Channel deleted.']);
    }

    public function members(Request $request, int $workspaceId): JsonResponse
    {
        $workspace = $this->authorizedWorkspace($request, $workspaceId);

        return response()->json([
            'members' => $workspace->members()
                ->orderBy('users.name')
                ->get()
                ->map(fn (User $member) => $this->memberPayload($member))
                ->all(),
        ]);
    }

    public function storeMember(Request $request, int $workspaceId): JsonResponse
    {
        $workspace = $this->authorizedWorkspace($request, $workspaceId);
        $this->ensureOwner($workspace);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(self::ROLES)],
        ]);

        $user = User::query()->where('email', strtolower($validated['email']))->first();
        abort_if($user === null, 404, 'Pengguna dengan email tersebut belum terdaftar.');
        abort_if(
            $workspace->members()->whereKey($user->id)->exists(),
            409,
            'Pengguna ini sudah menjadi anggota workspace.',
        );

        DB::transaction(function () use ($request, $workspace, $user, $validated): void {
            $workspace->members()->attach($user->id, ['role' => $validated['role']]);

            $this->audit($request, $workspace, 'member.added', $user->id, [
                'role' => $validated['role'],
                'email' => $user->email,
            ]);
        });

        return response()->json([
            'member' => $this->memberPayload($workspace->members()->whereKey($user->id)->first()),
        ], 201);
    }

    public function updateMember(Request $request, int $workspaceId, int $userId): JsonResponse
    {
        $workspace = $this->authorizedWorkspace($request, $workspaceId);
        $this->ensureOwner($workspace);
        $member = $workspace->members()->whereKey($userId)->firstOrFail();

        $validated = $request->validate([
            'role' => ['required', Rule::in(self::ROLES)],
        ]);

        $this->guardLastOwner($workspace, $member->pivot->role, $validated['role']);

        DB::transaction(function () use ($request, $workspace, $member, $validated): void {
            $workspace->members()->updateExistingPivot($member->id, ['role' => $validated['role']]);

            $this->audit($request, $workspace, 'member.updated', $member->id, [
                'role' => $validated['role'],
            ]);
        });

        return response()->json([
            'member' => $this->memberPayload(
                $workspace->members()->whereKey($member->id)->first(),
            ),
        ]);
    }

    public function deleteMember(Request $request, int $workspaceId, int $userId): JsonResponse
    {
        $workspace = $this->authorizedWorkspace($request, $workspaceId);
        $this->ensureOwner($workspace);
        $member = $workspace->members()->whereKey($userId)->firstOrFail();

        $this->guardLastOwner($workspace, $member->pivot->role, null);

        DB::transaction(function () use ($request, $workspace, $member): void {
            $this->audit($request, $workspace, 'member.removed', $member->id, [
                'email' => $member->email,
            ]);

            $workspace->members()->detach($member->id);
        });

        return response()->json(['message' => 'Member removed.']);
    }

    private function guardLastOwner(Workspace $workspace, string $currentRole, ?string $nextRole): void
    {
        if ($currentRole !== 'owner' || $nextRole === 'owner') {
            return;
        }

        $ownerCount = $workspace->members()->wherePivot('role', 'owner')->count();
        abort_if($ownerCount <= 1, 422, 'Workspace harus memiliki minimal satu owner.');
    }

    /**
     * @return array<string, mixed>
     */
    private function workspacePayload(Workspace $workspace): array
    {
        return [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'timezone' => $workspace->timezone,
            'role' => $workspace->pivot->role,
            'channel_count' => $workspace->channels()->count(),
            'member_count' => $workspace->members()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function channelPayload(Channel $channel): array
    {
        return [
            'id' => $channel->id,
            'name' => $channel->name,
            'platform' => $channel->platform,
            'external_id' => $channel->external_id,
            'is_active' => $channel->is_active,
            'settings' => $channel->settings,
            'project_count' => $channel->projects()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function memberPayload(User $member): array
    {
        return [
            'user_id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'role' => $member->pivot->role,
            'joined_at' => $member->pivot->created_at,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(Request $request, Workspace $workspace, string $action, int $subjectId, array $metadata): void
    {
        AuditLog::query()->create([
            'actor_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => 'workspace',
            'subject_id' => $subjectId,
            'metadata' => $metadata + ['workspace_id' => $workspace->id],
        ]);
    }

    private function authorizedWorkspace(Request $request, int $workspaceId): Workspace
    {
        return app(WorkspaceController::class)->authorizedWorkspace($request, $workspaceId);
    }

    private function ensureOwner(Workspace $workspace): void
    {
        abort_if($workspace->pivot->role !== 'owner', 403, 'Hanya owner workspace yang dapat mengubah pengaturan ini.');
    }

    private function ensureCanEdit(Workspace $workspace): void
    {
        abort_if($workspace->pivot->role === 'viewer', 403);
    }
}
