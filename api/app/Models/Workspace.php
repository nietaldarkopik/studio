<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    protected $fillable = ['name', 'slug', 'timezone'];

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function productionRuns(): HasMany
    {
        return $this->hasMany(ProductionRun::class);
    }
}
