<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScriptGeneration extends Model
{
    protected $fillable = [
        'workspace_id',
        'project_id',
        'requested_by',
        'format',
        'status',
        'model',
        'narration',
        'scenes',
        'word_count',
        'error_message',
        'started_at',
        'finished_at',
        'storyboard_imported_at',
    ];

    protected function casts(): array
    {
        return [
            'scenes' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'storyboard_imported_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }
}
