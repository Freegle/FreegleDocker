<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @see ../../database/migrations/2025_12_10_094529_create_users_push_notifications_table.php */
class UserPushNotification extends Model
{
    protected $table = 'users_push_notifications';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'added' => 'datetime',
        'lastsent' => 'datetime',
        'engageconsidered' => 'datetime',
        'engagesent' => 'datetime',
    ];
}
