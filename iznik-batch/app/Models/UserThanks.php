<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_users_thanks_table.php
 * @property int $id
 * @property int $userid
 * @property \Illuminate\Support\Carbon $timestamp
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserThanks newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserThanks newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserThanks query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserThanks whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserThanks whereTimestamp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserThanks whereUserid($value)
 * @mixin \Eloquent
 */
class UserThanks extends Model
{
    protected $table = 'users_thanks';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'timestamp' => 'datetime',
    ];
}
