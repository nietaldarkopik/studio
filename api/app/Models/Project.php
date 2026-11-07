<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Project extends Model
{
    protected $fillable = [
        'workspace_id',
        'channel_id',
        'created_by',
        'client_id',
        'title',
        'topic',
        'status',
        'mode',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function script(): HasOne
    {
        return $this->hasOne(Script::class);
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(Scene::class)->orderBy('position');
    }

    public function researchSources(): HasMany
    {
        return $this->hasMany(ResearchSource::class)->orderBy('id');
    }
}
