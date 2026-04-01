<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @see ../../database/migrations/2025_12_10_094529_create_users_requests_table.php */
class UserRequest extends Model
{
    protected $table = 'users_requests';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'date' => 'datetime',
        'completed' => 'datetime',
        'notifiedmods' => 'datetime',
        'paid' => 'boolean',
    ];
}
