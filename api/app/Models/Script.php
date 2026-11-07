<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Script extends Model
{
    protected $fillable = [
        'project_id',
        'format',
        'narration',
        'word_count',
        'version',
        'status',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
