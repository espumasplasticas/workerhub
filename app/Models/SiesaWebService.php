<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiesaWebService extends Model
{
    protected $table = 'siesa_web_services';

    public $timestamps = false;

    protected $fillable = [
        'worker_task_id',
        'task_type',
        'document_id',
        'source',
        'import_stage',
        'context',
        'xml',
        'result',
        'result_text',
        'ts',
        'processed_at',
    ];

    protected $casts = [
        'context' => 'array',
        'ts' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
