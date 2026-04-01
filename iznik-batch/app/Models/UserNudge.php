<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @see ../../database/migrations/2025_12_10_094529_create_users_nudges_table.php */
class UserNudge extends Model
{
    protected $table = 'users_nudges';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'timestamp' => 'datetime',
        'responded' => 'datetime',
    ];
}
