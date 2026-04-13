<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkerTaskEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'worker_task_id',
        'event',
        'level',
        'message',
        'context',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(WorkerTask::class, 'worker_task_id');
    }
}
