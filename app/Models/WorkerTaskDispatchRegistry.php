<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkerTaskDispatchRegistry extends Model
{
    use HasFactory;

    protected $fillable = [
        'entity_type',
        'entity_id',
        'document_id',
        'event_type',
        'source',
        'task_id',
        'accepted_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
    ];
}
