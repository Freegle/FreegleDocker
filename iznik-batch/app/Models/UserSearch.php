<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @see ../../database/migrations/2025_12_10_094529_create_users_searches_table.php */
class UserSearch extends Model
{
    protected $table = 'users_searches';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'date' => 'datetime',
        'deleted' => 'boolean',
    ];
}
