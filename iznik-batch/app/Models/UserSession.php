<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @see ../../database/migrations/2025_12_10_094529_create_sessions_table.php */
class UserSession extends Model
{
    protected $table = 'sessions';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'date' => 'datetime',
        'lastactive' => 'datetime',
    ];
}
