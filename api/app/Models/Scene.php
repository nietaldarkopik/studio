<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Scene extends Model
{
    protected $fillable = [
        'project_id',
        'position',
        'title',
        'narration',
        'visual_description',
        'camera_direction',
        'duration_seconds',
        'status',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
