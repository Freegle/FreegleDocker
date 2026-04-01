<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @see ../../database/migrations/2025_12_10_094529_create_users_nearby_table.php */
class UserNearby extends Model
{
    protected $table = 'users_nearby';
    protected $guarded = [];
    public $timestamps = false;
    public $primaryKey = null;
    public $incrementing = false;

    protected $casts = [
        'timestamp' => 'datetime',
    ];
}
