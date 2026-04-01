<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @see ../../database/migrations/2025_12_10_094529_create_users_invitations_table.php */
class UserInvitation extends Model
{
    protected $table = 'users_invitations';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'date' => 'datetime',
        'outcometimestamp' => 'datetime',
    ];
}
