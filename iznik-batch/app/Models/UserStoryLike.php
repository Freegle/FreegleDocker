<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @see ../../database/migrations/2025_12_10_094529_create_users_stories_likes_table.php */
class UserStoryLike extends Model
{
    protected $table = 'users_stories_likes';
    protected $guarded = [];
    public $timestamps = false;
    public $primaryKey = null;
    public $incrementing = false;
}
