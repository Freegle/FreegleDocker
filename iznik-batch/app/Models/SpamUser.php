<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @see ../../database/migrations/2025_12_10_094529_create_spam_users_table.php */
class SpamUser extends Model
{
    protected $table = 'spam_users';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'added' => 'datetime',
        'heldat' => 'datetime',
    ];
}
