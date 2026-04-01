<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @see ../../database/migrations/2025_12_10_094529_create_users_stories_requested_table.php */
class UserStoryRequested extends Model
{
    protected $table = 'users_stories_requested';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'date' => 'datetime',
    ];
}
