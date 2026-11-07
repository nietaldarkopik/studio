<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    public function show(Request $request, int $workspaceId): JsonResponse
    {
        $workspace = $this->authorizedWorkspace($request, $workspaceId);

        return response()->json([
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'timezone' => $workspace->timezone,
                'role' => $workspace->pivot->role,
            ],
            'projects' => [
                'total' => $workspace->projects()->count(),
                'drafts' => $workspace->projects()->where('status', 'draft')->count(),
            ],
            'recent_projects' => $workspace->projects()
                ->latest()
                ->limit(5)
                ->get(['id', 'title', 'topic', 'status', 'created_at']),
        ]);
    }

    public function authorizedWorkspace(Request $request, int $workspaceId): Workspace
    {
        return $request->user()
            ->workspaces()
            ->whereKey($workspaceId)
            ->firstOrFail();
    }
}
