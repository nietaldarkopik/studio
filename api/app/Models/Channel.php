<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    protected $fillable = [
        'workspace_id',
        'name',
        'platform',
        'external_id',
        'is_active',
        'settings',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'settings' => 'array'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
