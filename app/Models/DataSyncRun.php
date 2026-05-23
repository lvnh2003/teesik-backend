<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataSyncRun extends Model
{
    protected $fillable = [
        'source',
        'entity',
        'status',
        'started_at',
        'finished_at',
        'fetched_count',
        'upserted_count',
        'error',
        'logs',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'fetched_count' => 'integer',
        'upserted_count' => 'integer',
        'logs' => 'array',
    ];
}
