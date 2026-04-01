<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @see ../../database/migrations/2025_12_10_094529_create_users_logins_table.php */
class UserLogin extends Model
{
    protected $table = 'users_logins';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'added' => 'datetime',
        'lastaccess' => 'datetime',
        'credentialsrotated' => 'datetime',
    ];
}
