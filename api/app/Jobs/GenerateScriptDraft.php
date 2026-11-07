<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\ScriptGeneration;
use App\Services\AI\NineRouterClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateScriptDraft implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct(public int $generationId)
    {
    }

    public function handle(NineRouterClient $nineRouter): void
    {
        $generation = ScriptGeneration::query()->find($this->generationId);
        if ($generation === null || $generation->status === 'completed') {
            return;
        }

        $generation->update([
            'status' => 'running',
            'started_at' => now(),
            'error_message' => null,
        ]);

        try {
            $draft = $nineRouter->generateScriptDraft($generation->project->topic, $generation->format);
        } catch (Throwable $exception) {
            $generation->update([
                'status' => 'failed',
                'error_message' => $this->safeMessage($exception),
                'finished_at' => now(),
            ]);

            return;
        }

        $generation->update([
            'status' => 'completed',
            'model' => $draft['model'],
            'narration' => $draft['narration'],
            'scenes' => $draft['scenes'] === [] ? null : $draft['scenes'],
            'word_count' => $draft['word_count'],
            'finished_at' => now(),
        ]);

        AuditLog::query()->create([
            'actor_id' => $generation->requested_by,
            'action' => 'script.draft_generated',
            'subject_type' => 'project',
            'subject_id' => $generation->project_id,
            'metadata' => [
                'workspace_id' => $generation->workspace_id,
                'format' => $generation->format,
                'model' => $draft['model'],
                'word_count' => $draft['word_count'],
                'scene_count' => count($draft['scenes']),
            ],
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $generation = ScriptGeneration::query()->find($this->generationId);
        if ($generation === null || ! $generation->isActive()) {
            return;
        }

        $generation->update([
            'status' => 'failed',
            'error_message' => $exception === null
                ? 'Draf AI gagal dibuat.'
                : $this->safeMessage($exception),
            'finished_at' => now(),
        ]);
    }

    private function safeMessage(Throwable $exception): string
    {
        $message = $exception->getMessage();

        return str_starts_with($message, '9Router ') ? $message : 'Draf AI gagal dibuat.';
    }
}
