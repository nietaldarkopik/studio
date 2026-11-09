<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionRun extends Model
{
    /** Urutan tahap produksi otomatis. */
    public const STAGES = [
        'ideate' => 'Ide, tema & judul',
        'research' => 'Riset sumber',
        'script' => 'Naskah & fact-check',
        'storyboard' => 'Storyboard',
        'prompts' => 'Prompt visual',
        'tts' => 'Narasi suara (TTS)',
        'render' => 'Render Blender',
        'finalize' => 'Finalisasi video',
        'qc' => 'Quality control',
        'publish' => 'Upload YouTube',
    ];

    protected $fillable = [
        'workspace_id',
        'project_id',
        'channel_id',
        'requested_by',
        'status',
        'stage',
        'stages',
        'options',
        'artifacts',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'stages' => 'array',
            'options' => 'array',
            'artifacts' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return ($this->options ?? [])[$key] ?? $default;
    }

    public function artifact(string $key, mixed $default = null): mixed
    {
        return ($this->artifacts ?? [])[$key] ?? $default;
    }

    public function setArtifact(string $key, mixed $value): void
    {
        $artifacts = $this->artifacts ?? [];
        $artifacts[$key] = $value;
        $this->artifacts = $artifacts;
        $this->save();
    }

    public function stageState(string $key): array
    {
        return ($this->stages ?? [])[$key] ?? ['status' => 'pending'];
    }

    public function markStage(string $key, string $status, ?string $message = null): void
    {
        $stages = $this->stages ?? [];
        $state = $stages[$key] ?? [];
        $state['status'] = $status;
        $state['message'] = $message;
        if ($status === 'running') {
            $state['started_at'] = now()->toIso8601String();
        }
        if (in_array($status, ['completed', 'failed', 'skipped', 'needs_review'], true)) {
            $state['finished_at'] = now()->toIso8601String();
        }
        $stages[$key] = $state;
        $this->stages = $stages;
        $this->stage = $key;
        $this->save();
    }

    public function storageDir(): string
    {
        return 'production/run_'.$this->id;
    }
}
