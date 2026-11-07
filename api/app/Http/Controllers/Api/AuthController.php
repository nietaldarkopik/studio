<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $user = User::query()
            ->where('email', strtolower($credentials['email']))
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are invalid.'],
            ]);
        }

        $token = $user->createToken('studio-web', ['*'], now()->addDays(30));

        AuditLog::query()->create([
            'actor_id' => $user->id,
            'action' => 'auth.login',
            'metadata' => ['method' => 'password'],
        ]);

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        AuditLog::query()->create([
            'actor_id' => $user->id,
            'action' => 'auth.logout',
            'metadata' => ['method' => 'token'],
        ]);

        $user->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => $this->userPayload($user),
            'workspaces' => $user->workspaces()
                ->with('channels:id,workspace_id,name,platform,is_active')
                ->orderBy('workspaces.name')
                ->get()
                ->map(fn ($workspace) => [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                    'timezone' => $workspace->timezone,
                    'role' => $workspace->pivot->role,
                    'channels' => $workspace->channels->map(fn ($channel) => [
                        'id' => $channel->id,
                        'name' => $channel->name,
                        'platform' => $channel->platform,
                        'is_active' => $channel->is_active,
                    ]),
                ]),
        ]);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
}
