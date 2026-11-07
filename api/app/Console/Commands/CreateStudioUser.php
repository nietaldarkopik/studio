<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('studio:user-create')]
#[Description('Create the initial Studio owner account and workspace')]
class CreateStudioUser extends Command
{
    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->components->error('An account already exists. Public account registration is disabled.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Owner name'));
        $email = strtolower(trim((string) $this->ask('Owner email')));
        $workspaceName = trim((string) $this->ask('Workspace name', 'Ternyata Begini Workspace'));
        $password = (string) $this->secret('Password (minimum 12 characters)');
        $confirmation = (string) $this->secret('Confirm password');

        if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || $workspaceName === '') {
            $this->components->error('Name, a valid email, and a workspace name are required.');

            return self::FAILURE;
        }

        if (strlen($password) < 12 || ! hash_equals($password, $confirmation)) {
            $this->components->error('The password must be at least 12 characters and both entries must match.');

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->components->error('That email address is already registered.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($name, $email, $password, $workspaceName): void {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);

            $workspace = Workspace::query()->create([
                'name' => $workspaceName,
                'slug' => Str::slug($workspaceName),
            ]);
            $workspace->members()->attach($user->id, ['role' => 'owner']);

            Channel::query()->create([
                'workspace_id' => $workspace->id,
                'name' => 'Ternyata Begini',
                'platform' => 'youtube',
            ]);

            AuditLog::query()->create([
                'actor_id' => $user->id,
                'action' => 'studio.account_created',
                'subject_type' => 'workspace',
                'subject_id' => $workspace->id,
                'metadata' => ['workspace_id' => $workspace->id],
            ]);
        });

        $this->components->info('Studio owner account created. Sign in through the Studio UI.');

        return self::SUCCESS;
    }
}
