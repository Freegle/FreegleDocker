<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @see ../../database/migrations/2025_12_10_094529_create_users_banned_table.php */
class UserBanned extends Model
{
    protected $table = 'users_banned';
    protected $guarded = [];
    public $timestamps = false;
    public $primaryKey = null;
    public $incrementing = false;

    protected $casts = [
        'date' => 'datetime',
    ];
}
