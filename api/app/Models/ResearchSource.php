<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchSource extends Model
{
    protected $fillable = [
        'project_id',
        'created_by',
        'title',
        'url',
        'publisher',
        'published_at',
        'key_findings',
        'reliability_note',
        'verified',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'date',
            'verified' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
