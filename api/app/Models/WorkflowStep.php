<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowStep extends Model
{
    protected $fillable = [
        'workflow_id',
        'position',
        'key',
        'name',
        'type',
        'status',
        'requires_approval',
        'depends_on',
        'config',
        'approved_at',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'requires_approval' => 'boolean',
            'depends_on' => 'array',
            'config' => 'array',
            'approved_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    public function dependencies(): array
    {
        return $this->depends_on ?? [];
    }
}
