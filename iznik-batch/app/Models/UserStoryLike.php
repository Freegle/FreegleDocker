<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_users_stories_likes_table.php
 * @property int $storyid
 * @property int $userid
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserStoryLike newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserStoryLike newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserStoryLike query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserStoryLike whereStoryid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserStoryLike whereUserid($value)
 * @mixin \Eloquent
 */
class UserStoryLike extends Model
{
    protected $table = 'users_stories_likes';
    protected $guarded = [];
    public $timestamps = false;
    public $primaryKey = null;
    public $incrementing = false;
}
