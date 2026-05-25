<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataSyncState extends Model
{
    protected $fillable = [
        'source',
        'entity',
        'status',
        'last_synced_at',
        'last_started_at',
        'last_finished_at',
        'last_records_synced',
        'last_error',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'last_started_at' => 'datetime',
        'last_finished_at' => 'datetime',
        'last_records_synced' => 'integer',
    ];
}
