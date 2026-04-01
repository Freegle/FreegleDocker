<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @see ../../database/migrations/2025_12_10_094529_create_users_comments_table.php */
class UserComment extends Model
{
    protected $table = 'users_comments';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'date' => 'datetime',
        'reviewed' => 'datetime',
        'flag' => 'boolean',
    ];
}
