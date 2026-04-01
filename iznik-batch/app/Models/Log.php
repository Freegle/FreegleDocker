<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @see ../../database/migrations/2025_12_10_094529_create_logs_table.php */
class Log extends Model
{
    protected $table = 'logs';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'timestamp' => 'datetime',
    ];
}
