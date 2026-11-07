<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prompt extends Model
{
    protected $fillable = [
        'project_id',
        'scene_id',
        'created_by',
        'title',
        'positive',
        'negative',
        'style',
        'camera',
        'lighting',
        'mood',
        'notes',
        'status',
        'version',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(Scene::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(PromptRevision::class)->orderByDesc('version');
    }
}
