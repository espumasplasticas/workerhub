<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkerTask extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'parent_task_id',
        'type',
        'source',
        'status',
        'priority',
        'queue',
        'kafka_topic',
        'kafka_key',
        'attempts',
        'payload',
        'result',
        'metadata',
        'error_message',
        'requested_at',
        'published_at',
        'queued_at',
        'processing_at',
        'completed_at',
        'failed_at',
        'replayed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
        'metadata' => 'array',
        'requested_at' => 'datetime',
        'published_at' => 'datetime',
        'queued_at' => 'datetime',
        'processing_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'replayed_at' => 'datetime',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(WorkerTaskEvent::class, 'worker_task_id')->latest();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_task_id');
    }

    public function replays(): HasMany
    {
        return $this->hasMany(self::class, 'parent_task_id')->latest('created_at');
    }
}
